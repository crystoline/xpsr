<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Tests;

use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Broadcast\Http\SubscriptionsMiddleware;
use Crystoline\Xpsr\Broadcast\Tests\Fixtures\Project;
use DateTimeInterface;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class SubscriptionsTest extends TestCase
{
    /** @var list<Project> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->remove();
        }
    }

    public function testSelectorsComeFromTheConfigurationForSignedInUsers(): void
    {
        $project = $this->project();
        $subscriptions = $project->subscriptions();
        self::assertSame(['users/7/{+rest}', 'announcements'], $subscriptions->selectors($project->ada), 'From the closure.');
        self::assertSame([], $subscriptions->selectors(null), 'A closure typed UserInterface is not called for guests.');
        self::assertNull($subscriptions->token(null));
        self::assertNull($subscriptions->cookie(null));

        $list = $this->project(['subscriptions' => ['announcements', 'users/{id}/{+rest}']])->subscriptions();
        self::assertSame(['announcements', 'users/{id}/{+rest}'], $list->selectors($project->ada), 'A list: the same for every signed-in user.');
        self::assertSame([], $list->selectors(null));

        $guests = $this->project(['subscriptions' => static fn(?UserInterface $user): array => $user === null ? ['announcements'] : ['announcements', "users/{$user->authIdentifier()}/{+rest}"]])->subscriptions();
        self::assertSame(['announcements'], $guests->selectors(null), 'A closure accepting null is asked about guests.');
        self::assertSame(['announcements', 'users/7/{+rest}'], $guests->selectors($project->ada));

        $broken = $this->project(['subscriptions' => static fn(UserInterface $user): string => 'users/*'])->subscriptions();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('broadcast.subscriptions must return a list of topic selectors.');
        $broken->selectors($project->ada);
    }

    public function testTheCookieCarriesASignedTokenForTheHubAlone(): void
    {
        $project = $this->project();
        $cookie = $project->subscriptions()->cookie($project->ada);
        self::assertNotNull($cookie);
        self::assertSame('mercureAuthorization', $cookie->name);
        self::assertSame(3600, $cookie->maxAge);
        self::assertSame('/.well-known/mercure', $cookie->path, 'Sent to the hub only.');
        self::assertNull($cookie->domain);
        self::assertTrue($cookie->secure, 'The public hub is https.');
        self::assertTrue($cookie->httpOnly);
        self::assertSame('Strict', $cookie->sameSite);
        $token = $this->parse($cookie->value);
        self::assertSame(['subscribe' => ['users/7/{+rest}', 'announcements']], $token->claims()->get('mercure'));
        $expires = $token->claims()->get('exp');
        self::assertInstanceOf(DateTimeInterface::class, $expires);
        self::assertSame(1793700000 + 3600, $expires->getTimestamp());

        $local = $this->project(['public_hub' => 'http://localhost:3000/.well-known/mercure', 'cookie' => 'mercure_auth', 'cookie_domain' => 'example.com', 'ttl' => null])->subscriptions()->cookie($project->ada);
        self::assertNotNull($local);
        self::assertSame('mercure_auth', $local->name);
        self::assertFalse($local->secure, 'An http hub, for development.');
        self::assertSame('example.com', $local->domain);
        self::assertNull($local->maxAge, 'For the browser session.');
        self::assertFalse($this->parse($local->value)->claims()->has('exp'));
    }

    public function testAuthorizeSetsTheCookieAndForgetRemovesIt(): void
    {
        $project = $this->project();
        $factory = new Psr17Factory();
        $subscriptions = $project->subscriptions();

        $response = $subscriptions->authorize($factory->createResponse(200), $project->ada);
        self::assertMatchesRegularExpression('#^mercureAuthorization=[A-Za-z0-9_.-]+; Max-Age=3600; Path=/\.well-known/mercure; Secure; HttpOnly; SameSite=Strict$#', $response->getHeaderLine('Set-Cookie'));

        $guest = $subscriptions->authorize($factory->createResponse(200), null);
        self::assertSame('mercureAuthorization=; Max-Age=0; Path=/.well-known/mercure; Secure; HttpOnly; SameSite=Strict', $guest->getHeaderLine('Set-Cookie'), 'A guest gets the cookie removed.');
        self::assertSame($guest->getHeaderLine('Set-Cookie'), $subscriptions->forget($factory->createResponse(200))->getHeaderLine('Set-Cookie'));
        $local = $this->project(['public_hub' => 'http://localhost:3000/.well-known/mercure'])->subscriptions();
        self::assertSame('mercureAuthorization=; Max-Age=0; Path=/.well-known/mercure; HttpOnly; SameSite=Strict', $local->forget($factory->createResponse(200))->getHeaderLine('Set-Cookie'), 'Removed with the attributes it was set with.');

        self::assertSame(['subscribe' => ['users/7/{+rest}', 'announcements']], $this->parse((string) $subscriptions->token($project->ada))->claims()->get('mercure'), 'token() for a client that sends a bearer.');
    }

    public function testHubUrlInATemplateSetsTheCookieOfTheSignedInUser(): void
    {
        $project = $this->project();
        $project->actingAs($project->ada);
        $response = $project->request('GET', '/page');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('<div data-hub="https://hub.test/.well-known/mercure?topic=orders%2F42"></div>', trim((string) $response->getBody()), 'The URL, escaped for the attribute.');
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith('mercureAuthorization=', $cookie);
        self::assertStringEndsWith('; Max-Age=3600; Path=/.well-known/mercure; Secure; HttpOnly; SameSite=Strict', $cookie);
        self::assertSame(['subscribe' => ['users/7/{+rest}', 'announcements']], $this->parse(explode(';', substr($cookie, strlen('mercureAuthorization=')))[0])->claims()->get('mercure'));

        $project->actingAs(null);
        $guest = $project->request('GET', '/page');
        self::assertSame(200, $guest->getStatusCode());
        self::assertSame('', $guest->getHeaderLine('Set-Cookie'), 'A guest may subscribe to nothing private, so no cookie.');

        $project->actingAs($project->ada);
        $public = $project->request('GET', '/public');
        self::assertSame('https://hub.test/.well-known/mercure?topic=news', trim((string) $public->getBody()));
        self::assertSame('', $public->getHeaderLine('Set-Cookie'), 'hub_url(topics, false) sets no cookie.');

        $other = $project->request('POST', '/logout');
        self::assertSame('mercureAuthorization=; Max-Age=0; Path=/.well-known/mercure; Secure; HttpOnly; SameSite=Strict', $other->getHeaderLine('Set-Cookie'));
    }

    public function testHubUrlNeedsTheMiddlewareToSetACookie(): void
    {
        $project = $this->project(middleware: false);
        $project->actingAs($project->ada);
        self::assertSame(200, $project->request('GET', '/public')->getStatusCode(), 'No cookie wanted, no middleware needed.');
        $project->actingAs(null);
        self::assertSame(200, $project->request('GET', '/page')->getStatusCode(), 'A guest gets no cookie either.');
        self::assertSame('https://hub.test/.well-known/mercure?topic=orders%2F42', $project->subscriptions()->hubUrl(['orders/42']), 'Outside a request nobody is signed in, so no cookie.');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('hub_url() sets the authorization cookie through ' . SubscriptionsMiddleware::class . ': add it to app.middleware');
        $project->subscriptions()->hubUrl(['orders/42'], user: $project->ada);
    }

    public function testTheAuthorizeEndpointAnswersTheHubAndTheSelectorsWithTheCookie(): void
    {
        $project = $this->project();
        $project->actingAs($project->ada);
        $response = $project->request('GET', '/broadcast/authorize');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['hub' => 'https://hub.test/.well-known/mercure', 'selectors' => ['users/7/{+rest}', 'announcements']], json_decode((string) $response->getBody(), true));
        self::assertStringStartsWith('mercureAuthorization=ey', $response->getHeaderLine('Set-Cookie'));

        $project->actingAs(new GenericUser(8, null));
        $other = $project->request('GET', '/broadcast/authorize');
        self::assertSame(['hub' => 'https://hub.test/.well-known/mercure', 'selectors' => ['users/8/{+rest}', 'announcements']], json_decode((string) $other->getBody(), true));

        $project->actingAs(null);
        $guest = $project->request('GET', '/broadcast/authorize');
        self::assertSame(['hub' => 'https://hub.test/.well-known/mercure', 'selectors' => []], json_decode((string) $guest->getBody(), true));
        self::assertStringStartsWith('mercureAuthorization=; Max-Age=0;', $guest->getHeaderLine('Set-Cookie'));
    }

    /** @param array<string, mixed> $broadcast */
    private function project(array $broadcast = [], bool $middleware = true): Project
    {
        $project = new Project($broadcast, middleware: $middleware);
        $project->boot();
        $this->projects[] = $project;

        return $project;
    }

    private function parse(string $jwt): UnencryptedToken
    {
        $token = (new Parser(new JoseEncoder()))->parse($jwt);
        self::assertInstanceOf(UnencryptedToken::class, $token);
        self::assertTrue((new Validator())->validate($token, new SignedWith(new Sha256(), InMemory::plainText(Project::SECRET))), 'Signed with the secret.');

        return $token;
    }
}
