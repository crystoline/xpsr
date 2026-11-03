<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Tests;

use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Translation\Http\LocaleMiddleware;
use Crystoline\Xpsr\Translation\Tests\Fixtures\Project;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LocaleMiddlewareTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheLocaleComesFromTheRouteTheCookieOrTheHeaderAndGoesBackAfter(): void
    {
        self::assertSame(['message' => 'Welcome, Ada!', 'locale' => 'en', 'attribute' => 'en'], $this->json($this->project->request('GET', '/hello')));
        self::assertSame(['message' => 'Bienvenue, Ada !', 'locale' => 'fr', 'attribute' => 'fr'], $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'fr-CA,fr;q=0.9,en;q=0.8'])), 'fr-CA matches fr by language.');
        self::assertSame('en', $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'de-DE,de;q=0.9']))['locale'], 'An unsupported language: app.locale.');
        self::assertSame('en', $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'de;q=0.9,en;q=0.5']))['locale'], 'The first supported one by quality.');
        self::assertSame('fr', $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'en;q=0.5,fr']))['locale'], 'Quality over position.');
        self::assertSame('pt_BR', $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'pt-BR']))['locale']);
        self::assertSame('pt_BR', $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'pt']))['locale'], 'A language matches its regional locale.');
        self::assertSame('fr', $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'en'], ['locale' => 'fr']))['locale'], 'The cookie comes before the header.');
        self::assertSame('en', $this->json($this->project->request('GET', '/hello', [], ['locale' => 'xx']))['locale'], 'An unsupported cookie is ignored.');
        self::assertSame(['message' => 'Bienvenue, Ada !', 'locale' => 'fr', 'attribute' => 'fr'], $this->json($this->project->request('GET', '/fr/hello', ['Accept-Language' => 'en'])), 'The route parameter, as route middleware.');
        self::assertSame('pt_BR', $this->json($this->project->request('GET', '/pt-BR/hello'))['locale']);
        self::assertSame('en', $this->json($this->project->request('GET', '/de/hello'))['locale']);
        self::assertSame('en', $this->project->locale()->get(), 'Back to app.locale after each request.');
    }

    public function testTheSessionValueAndTheResolverOrderAreConfigurable(): void
    {
        $middleware = $this->project->application->get(LocaleMiddleware::class);
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/')->withAttribute(Session::class, new Session(null, ['locale' => 'fr']))->withHeader('Accept-Language', 'en');
        self::assertSame('fr', $middleware->resolve($request), 'The session before the header.');
        self::assertSame('en', $middleware->resolve($request->withAttribute(Session::class, new Session(null, ['locale' => 'xx']))), 'An unsupported session value falls through to the header.');
        self::assertNull($middleware->resolve($factory->createServerRequest('GET', '/')));
        $this->project->remove();

        $this->project = new Project(['resolvers' => ['header'], 'cookie' => 'lang']);
        $this->project->boot();
        self::assertSame('en', $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'en'], ['locale' => 'fr', 'lang' => 'fr']))['locale'], 'Only the header is read.');
        $this->project->remove();

        $this->project = new Project(['resolvers' => ['cookie', 'header'], 'cookie' => 'lang']);
        $this->project->boot();
        self::assertSame('fr', $this->json($this->project->request('GET', '/hello', ['Accept-Language' => 'en'], ['lang' => 'fr']))['locale'], 'The renamed cookie.');

        /** @var \ArrayObject<int, string> $seen */
        $seen = new \ArrayObject();
        $handler = new class ($seen) implements RequestHandlerInterface {
            /** @param \ArrayObject<int, string> $seen */
            public function __construct(private readonly \ArrayObject $seen) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen[] = (string) $request->getAttribute(LocaleMiddleware::ATTRIBUTE);

                return (new Psr17Factory())->createResponse(204);
            }
        };
        $this->project->application->get(LocaleMiddleware::class)->process($factory->createServerRequest('GET', '/')->withCookieParams(['lang' => 'fr']), $handler);
        self::assertSame(['fr'], $seen->getArrayCopy(), 'The locale is a request attribute for the handler.');
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
