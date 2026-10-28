<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Closure;
use Crystoline\Xpsr\Session\ArraySessionStore;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\CurrentSession;
use Crystoline\Xpsr\Session\InvalidCsrfTokenException;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionManager;
use Crystoline\Xpsr\Session\SessionMiddleware;
use Crystoline\Xpsr\Session\SessionOptions;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

final class SessionMiddlewareTest extends TestCase
{
    private MockClock $clock;
    private ArraySessionStore $store;
    private CurrentSession $current;
    private ?string $cookie = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-28 12:00:00');
        $this->store = new ArraySessionStore(7200, $this->clock);
        $this->current = new CurrentSession();
    }

    public function testAPageThatDoesNotUseTheSessionSetsNoCookie(): void
    {
        $response = $this->visit(static fn() => null);
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(0, $this->store->prune());
    }

    public function testStoringAValueSendsTheSessionCookieAndLaterRequestsSeeIt(): void
    {
        $first = $this->visit(static fn(Session $session) => $session->set('cart', [12]));
        self::assertMatchesRegularExpression('/\Axpsr_session=[0-9a-f]{40}; Max-Age=7200; Path=\/; HttpOnly; SameSite=Lax\z/', $first->getHeaderLine('Set-Cookie'));

        $seen = null;
        $this->visit(static function (Session $session) use (&$seen): void {
            $seen = $session->get('cart');
        });
        self::assertSame([12], $seen);
    }

    public function testTheCookieIsSecureOnHttpsOrWhenConfigured(): void
    {
        $response = $this->visit(static fn(Session $session) => $session->set('a', 1), https: true);
        self::assertStringContainsString('; Secure;', $response->getHeaderLine('Set-Cookie'));

        $this->cookie = null;
        $configured = $this->visit(static fn(Session $session) => $session->set('a', 1), options: ['cookie' => ['secure' => true, 'same_site' => 'Strict', 'name' => 'app', 'path' => '/admin', 'domain' => 'example.com']]);
        self::assertMatchesRegularExpression('/\Aapp=[0-9a-f]{40}; Max-Age=7200; Path=\/admin; Domain=example.com; Secure; HttpOnly; SameSite=Strict\z/', $configured->getHeaderLine('Set-Cookie'));
    }

    public function testAnUnknownOrMalformedIdFromTheBrowserIsNeverAdopted(): void
    {
        foreach ([str_repeat('a', 40), '../../etc/passwd', ''] as $planted) {
            $this->cookie = $planted;
            $response = $this->visit(static fn(Session $session) => $session->set('user_id', 7));
            self::assertNotSame($planted, $this->cookie, 'The browser gets a new ID.');
            self::assertNotNull($this->store->read((string) $this->cookie));
            self::assertNull($this->store->read(str_repeat('a', 40)));
            self::assertStringStartsWith('xpsr_session=' . $this->cookie, $response->getHeaderLine('Set-Cookie'));
        }
    }

    public function testRegeneratingMovesTheSessionAndRetiresTheOldId(): void
    {
        $this->visit(static fn(Session $session) => $session->set('cart', [1]));
        $old = $this->cookie;
        $this->visit(static function (Session $session): void {
            $session->regenerate();
            $session->set('user_id', 7);
        });

        self::assertNotSame($old, $this->cookie);
        self::assertNull($this->store->read((string) $old));
        $seen = null;
        $this->visit(static function (Session $session) use (&$seen): void {
            $seen = $session->all();
        });
        self::assertSame(['cart' => [1], 'user_id' => 7], $seen);
    }

    public function testInvalidatingDeletesTheSessionAndTheCookie(): void
    {
        $this->visit(static fn(Session $session) => $session->set('user_id', 7));
        $old = $this->cookie;
        $response = $this->visit(static fn(Session $session) => $session->invalidate());

        self::assertSame('xpsr_session=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        self::assertNull($this->cookie);
        self::assertNull($this->store->read((string) $old));
    }

    public function testAnExpiredSessionStartsOver(): void
    {
        $this->visit(static fn(Session $session) => $session->set('user_id', 7));
        $this->clock->sleep(7200);
        $seen = 'not visited';
        $response = $this->visit(static function (Session $session) use (&$seen): void {
            $seen = $session->get('user_id');
        });
        self::assertNull($seen);
        self::assertSame('xpsr_session=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax', $response->getHeaderLine('Set-Cookie'), 'The stale cookie is removed.');
    }

    public function testChangesAreNotSavedWhenTheHandlerThrows(): void
    {
        $this->visit(static fn(Session $session) => $session->set('step', 1));
        try {
            $this->visit(static function (Session $session): void {
                $session->set('step', 2);
                throw new RuntimeException('Failed.');
            });
            self::fail('The exception was swallowed.');
        } catch (RuntimeException) {
        }
        $seen = null;
        $this->visit(static function (Session $session) use (&$seen): void {
            $seen = $session->get('step');
        });
        self::assertSame(1, $seen);
        self::assertNull($this->current->get(), 'The current session is cleared even after an error.');
    }

    public function testTheCurrentSessionIsSetOnlyWhileTheRequestIsHandled(): void
    {
        $during = null;
        $this->visit(function (Session $session) use (&$during): void {
            $during = $this->current->get() === $session;
        });
        self::assertTrue($during);
        self::assertNull($this->current->get());
    }

    public function testCsrfLetsSafeMethodsThrough(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS', 'TRACE'] as $method) {
            self::assertSame(200, $this->visit(static fn() => null, method: $method, csrf: true)->getStatusCode(), $method);
        }
    }

    public function testCsrfRejectsUnsafeRequestsWithoutTheSessionsToken(): void
    {
        $token = null;
        $this->visit(static function (Session $session) use (&$token): void {
            $token = $session->token();
        });
        self::assertIsString($token);

        $attempts = [
            'no token' => [[], null],
            'wrong field' => [[], ['_token' => str_repeat('0', 64)]],
            'wrong header' => [['X-CSRF-Token' => 'nope'], null],
            'empty field, no header' => [[], ['_token' => '']],
            'token in an array' => [[], ['_token' => [$token]]],
        ];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach ($attempts as $name => [$headers, $body]) {
                try {
                    $this->visit(static fn() => null, method: $method, csrf: true, headers: $headers, body: $body);
                    self::fail("{$method} with {$name} was accepted.");
                } catch (InvalidCsrfTokenException $exception) {
                    self::assertSame(403, $exception->getStatusCode());
                    self::assertSame('This form has expired. Reload the page and try again.', $exception->publicMessage());
                }
            }
        }
    }

    public function testCsrfAcceptsTheTokenInTheFormOrTheHeader(): void
    {
        $token = null;
        $this->visit(static function (Session $session) use (&$token): void {
            $token = $session->token();
        });
        self::assertIsString($token);

        self::assertSame(200, $this->visit(static fn() => null, method: 'POST', csrf: true, body: ['_token' => $token])->getStatusCode());
        self::assertSame(200, $this->visit(static fn() => null, method: 'DELETE', csrf: true, headers: ['X-CSRF-Token' => $token])->getStatusCode());
    }

    public function testCsrfRejectsABrowserWithoutASession(): void
    {
        $this->expectException(InvalidCsrfTokenException::class);
        $this->visit(static fn() => null, method: 'POST', csrf: true, body: ['_token' => (new Session())->token()]);
    }

    public function testPruningRunsAboutOnceInPruneEveryRequests(): void
    {
        $this->visit(static fn(Session $session) => $session->set('a', 1));
        $old = (string) $this->cookie;
        $this->cookie = null;
        $this->clock->sleep(7200);
        $this->visit(static fn() => null);
        $this->clock->modify('-2 hours');
        self::assertNotNull($this->store->read($old), 'prune_every 0 never prunes.');

        $this->clock->modify('+2 hours');
        $this->visit(static fn() => null, options: ['prune_every' => 1]);
        $this->clock->modify('-2 hours');
        self::assertNull($this->store->read($old), 'The expired session was pruned.');
    }

    /**
     * Send a request through SessionMiddleware (and CsrfMiddleware) to $action, as a browser that
     * keeps the session cookie in $this->cookie.
     *
     * @param Closure(Session): mixed $action
     * @param array<array-key, mixed> $options
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function visit(Closure $action, string $method = 'GET', bool $https = false, array $options = [], bool $csrf = false, array $headers = [], ?array $body = null): ResponseInterface
    {
        $options = SessionOptions::fromArray($options + ['lifetime' => '2 hours', 'prune_every' => 0], '/app');
        $request = new ServerRequest($method, ($https ? 'https' : 'http') . '://localhost/', $headers);
        if ($this->cookie !== null) {
            $request = $request->withCookieParams([$options->cookieName => $this->cookie]);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $handler = new class ($action) implements RequestHandlerInterface {
            public function __construct(private readonly Closure $action) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->action)(Session::of($request));

                return new Response(200);
            }
        };
        if ($csrf) {
            $handler = self::wrap(new CsrfMiddleware(), $handler);
        }
        $response = (new SessionMiddleware(new SessionManager($this->store, $options), $this->current))->process($request, $handler);
        foreach ($response->getHeader('Set-Cookie') as $header) {
            preg_match('/\A[^=]+=([^;]*)/', $header, $match);
            $this->cookie = str_contains($header, 'Max-Age=0;') ? null : $match[1];
        }

        return $response;
    }

    private static function wrap(MiddlewareInterface $middleware, RequestHandlerInterface $next): RequestHandlerInterface
    {
        return new class ($middleware, $next) implements RequestHandlerInterface {
            public function __construct(private readonly MiddlewareInterface $middleware, private readonly RequestHandlerInterface $next) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }
}
