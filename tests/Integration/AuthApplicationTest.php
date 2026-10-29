<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Auth\Access\Gate;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\InMemoryUserProvider;
use Crystoline\Xpsr\Auth\Jwt\JwtServiceRegistry;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Tests\TestCase;

/**
 * Authentication in the skeleton's full HTTP kernel: browser sign-in with the session guard,
 * a JWT API with scopes, policies in views, and TestCase::actingAs().
 */
final class AuthApplicationTest extends TestCase
{
    private InMemoryUserProvider $users;

    protected function setUp(): void
    {
        $application = $this->application();
        $config = $application->getConfig();
        $config->set('routing.files', ['routes/api.php', 'routes/web.php', 'tests/Fixtures/routes/auth.php']);
        $config->set('app.view_dir', dirname(__DIR__) . '/Fixtures/views');
        $config->set('auth.providers', ['users' => ['driver' => 'test.users']]);
        $config->set('auth.jwt.secret', 'base64:' . base64_encode(str_repeat('k', 32)));
        $this->users = new InMemoryUserProvider();
        $this->users->add(new GenericUser(7, (new PasswordHasher())->hash('correct horse'), ['name' => 'Ada']), login: 'ada@example.com');
        $application->getContainer()->instance('test.users', $this->users);
        // The skeleton does not install the JWT package; register it as an application would.
        (new JwtServiceRegistry($application->getContainer()))->register();
        $application->get(Gate::class)->define('view-reports', static fn(GenericUser $user): bool => $user->get('name') === 'Ada');
    }

    public function testABrowserSignsInIsSentBackAndSignsOut(): void
    {
        $guest = $this->get('/account');
        self::assertSame([302, '/login'], [$guest->getStatusCode(), $guest->getHeaderLine('Location')]);
        self::assertSame(200, $this->get('/login')->getStatusCode());

        $wrong = $this->postForm('/login', ['email' => 'ada@example.com', 'password' => 'wrong']);
        self::assertSame([303, '/login'], [$wrong->getStatusCode(), $wrong->getHeaderLine('Location')]);
        self::assertStringContainsString('<p>Those details do not match.</p>', (string) $this->get('/login')->getBody());

        $before = $this->session()->id();
        $signedIn = $this->postForm('/login', ['email' => 'ada@example.com', 'password' => 'correct horse']);
        self::assertSame([303, '/account'], [$signedIn->getStatusCode(), $signedIn->getHeaderLine('Location')], 'Back to the page that asked.');
        self::assertNotSame($before, $this->session()->id(), 'Signing in moves the session to a new ID.');

        $account = $this->get('/account');
        self::assertSame(200, $account->getStatusCode());
        self::assertSame('Signed in as Ada. Reports', (string) $account->getBody());
        $login = $this->get('/login');
        self::assertSame([302, '/'], [$login->getStatusCode(), $login->getHeaderLine('Location')], 'Signed-in users skip the login page.');

        self::assertSame(303, $this->postForm('/logout', [])->getStatusCode());
        self::assertSame(302, $this->get('/account')->getStatusCode());
    }

    public function testAnApiClientSignsInWithAJwtAndItsScopesAreChecked(): void
    {
        $login = $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'correct horse']);
        self::assertSame(200, $login->getStatusCode());
        $token = json_decode((string) $login->getBody(), true)['token'];

        $me = $this->request('GET', '/api/me', ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
        self::assertSame(200, $me->getStatusCode());
        self::assertSame(['id' => 7, 'scopes' => ['me:read']], json_decode((string) $me->getBody(), true));
        self::assertFalse($me->hasHeader('Set-Cookie'), 'API requests have no session.');

        $denied = $this->request('DELETE', '/api/me', ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('Bearer error="insufficient_scope", scope="me:delete"', $denied->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['message' => 'This token does not allow this action.', 'scopes' => ['me:delete']], json_decode((string) $denied->getBody(), true));
    }

    public function testApiRequestsWithoutAValidTokenGetA401(): void
    {
        $missing = $this->request('GET', '/api/me', ['Accept' => 'application/json']);
        self::assertSame(401, $missing->getStatusCode());
        self::assertSame('Bearer', $missing->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['message' => 'Authentication is required.'], json_decode((string) $missing->getBody(), true));

        $invalid = $this->request('GET', '/api/me', ['Authorization' => 'Bearer a.b.c', 'Accept' => 'application/json']);
        self::assertSame('Bearer error="invalid_token"', $invalid->getHeaderLine('WWW-Authenticate'));

        self::assertSame(401, $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong'])->getStatusCode());
    }

    public function testTestsCanActAsAUser(): void
    {
        $ada = $this->users->findById(7) ?? self::fail();
        $this->actingAs($ada, 'api', ['me:read']);
        self::assertSame(200, $this->request('GET', '/api/me', ['Accept' => 'application/json'])->getStatusCode());
        self::assertSame(302, $this->get('/account')->getStatusCode(), 'Only the api guard was given.');

        $this->actingAs($ada);
        self::assertSame('Signed in as Ada. Reports', (string) $this->get('/account')->getBody());
    }
}
