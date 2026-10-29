<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\AuthOptions;
use Crystoline\Xpsr\Auth\BearerToken;
use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\GuardFactoryInterface;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\Http\Authenticate;
use Crystoline\Xpsr\Auth\Http\Guest;
use Crystoline\Xpsr\Auth\Http\RequireScopes;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\InMemoryUserProvider;
use Crystoline\Xpsr\Auth\InsufficientScopeException;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Crystoline\Xpsr\Auth\SessionGuard;
use Crystoline\Xpsr\Auth\UnauthenticatedException;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\FactoryRegistry;
use Crystoline\Xpsr\Registry\SessionServiceRegistry;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthTest extends TestCase
{
    private string $root;
    private Application $application;
    private InMemoryUserProvider $users;
    private PasswordHasher $hasher;
    private bool $booted = false;

    protected function setUp(): void
    {
        $this->root = TemporaryDirectory::create('auth app');
        mkdir($this->root . '/config');
        $this->application = new Application($this->root);
        $this->hasher = new PasswordHasher();
        $this->users = new InMemoryUserProvider();
        $this->users->add(new GenericUser(7, $this->hasher->hash('correct horse'), ['email' => 'ada@example.com', 'name' => 'Ada']), login: 'ada@example.com');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    public function testPasswordsAreHashedWithArgon2idOrBcrypt(): void
    {
        $hash = $this->hasher->hash('secret');
        self::assertSame(defined('PASSWORD_ARGON2ID') ? 'argon2id' : '2y', password_get_info($hash)['algo']);
        self::assertTrue($this->hasher->verify('secret', $hash));
        self::assertFalse($this->hasher->verify('Secret', $hash));
        self::assertFalse($this->hasher->verify('secret', null), 'A user without a password never matches.');
        self::assertFalse($this->hasher->needsRehash($hash));
        self::assertTrue($this->hasher->needsRehash(password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4])));

        $this->expectException(InvalidArgumentException::class);
        $this->hasher->hash(str_repeat('x', PasswordHasher::MAX_LENGTH + 1));
    }

    public function testVerifyChecksCredentialsAndUpgradesOldHashes(): void
    {
        $this->users->add(new GenericUser(8, password_hash('old secret', PASSWORD_BCRYPT, ['cost' => 4])), login: 'old@example.com');
        $auth = $this->auth();

        self::assertNull($auth->verify('ada@example.com', 'wrong'));
        self::assertNull($auth->verify('nobody@example.com', 'correct horse'));
        self::assertSame(7, $auth->verify('ada@example.com', 'correct horse')?->authIdentifier());

        self::assertNotNull($auth->verify('old@example.com', 'old secret'));
        $upgraded = (string) $this->users->findById(8)?->authPasswordHash();
        self::assertFalse($this->hasher->needsRehash($upgraded), 'The hash was replaced with a current one.');
        self::assertTrue($this->hasher->verify('old secret', $upgraded));
    }

    public function testSigningInStoresTheUserInANewSessionAndSigningOutEndsIt(): void
    {
        $auth = $this->auth();
        $session = new Session();
        $session->set('cart', [1]);
        $request = $this->request($session);
        $before = $session->id();

        self::assertNull($auth->attempt($request, 'ada@example.com', 'wrong'));
        self::assertSame($before, $session->id(), 'A failed attempt keeps the session.');
        self::assertNotNull($auth->attempt($request, 'ada@example.com', 'correct horse'));
        self::assertNotSame($before, $session->id(), 'Signing in moves the session to a new ID.');
        self::assertSame([1], $session->get('cart'));
        self::assertSame(7, $auth->authenticate($request)?->user->authIdentifier());
        self::assertSame(7, $this->application->get(CurrentIdentity::class)->get()?->user->authIdentifier());

        $auth->logout($request);
        self::assertNull($auth->authenticate($request));
        self::assertSame([], $session->all());
        self::assertNull($this->application->get(CurrentIdentity::class)->get());
    }

    public function testChangingThePasswordSignsOtherSessionsOut(): void
    {
        $auth = $this->auth();
        $session = new Session();
        $request = $this->request($session);
        $auth->login($request, $this->users->findById(7) ?? self::fail());
        self::assertNotNull($auth->authenticate($request));

        $this->users->updatePasswordHash($this->users->findById(7) ?? self::fail(), $this->hasher->hash('new password'));
        self::assertNull($auth->authenticate($request));
        self::assertFalse($session->has(SessionGuard::key('web')));
    }

    public function testADeletedUserIsSignedOut(): void
    {
        $session = new Session();
        $session->set(SessionGuard::key('web'), ['id' => 99, 'password' => 'x']);
        self::assertNull($this->auth()->authenticate($this->request($session)));
        self::assertFalse($session->has(SessionGuard::key('web')));
    }

    public function testBrowsersAreSentToSignInAndBackToThePageTheyAskedFor(): void
    {
        $session = new Session();
        $response = $this->through(Authenticate::class, $this->request($session, 'GET', '/tasks?page=2'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));

        $auth = $this->auth();
        self::assertSame('/tasks?page=2', $auth->intended($this->request($session)));
        self::assertSame('/dashboard', $auth->intended($this->request($session), '/dashboard'), 'The page is used once.');
        self::assertSame('/', $auth->intended($this->request($session)));

        $session->set('_intended.web', '//evil.example/steal');
        self::assertSame('/', $auth->intended($this->request($session)), 'Only paths on this site.');
    }

    public function testScriptsAndFormPostsGetA401Instead(): void
    {
        foreach ([['GET', ['Accept' => 'application/json']], ['GET', ['X-Requested-With' => 'XMLHttpRequest']], ['POST', []]] as [$method, $headers]) {
            try {
                $this->through(Authenticate::class, $this->request(new Session(), $method, '/tasks', $headers));
                self::fail('The guest was let through.');
            } catch (UnauthenticatedException $exception) {
                self::assertSame(401, $exception->getStatusCode());
                self::assertSame([], $exception->getHeaders(), 'The session guard sends no Bearer challenge.');
                self::assertSame('Authentication is required.', $exception->publicMessage());
            }
        }
    }

    public function testSignedInRequestsCarryTheirIdentity(): void
    {
        $session = new Session();
        $this->auth()->login($this->request($session), $this->users->findById(7) ?? self::fail());
        $current = $this->application->get(CurrentIdentity::class);
        $current->set(null);
        $seen = null;
        $during = null;
        $this->through(Authenticate::class, $this->request($session), static function (ServerRequestInterface $request) use (&$seen, &$during, $current): void {
            $seen = Identity::of($request);
            $during = $current->get();
        });

        self::assertInstanceOf(Identity::class, $seen);
        self::assertSame(7, $seen->user->authIdentifier());
        self::assertSame('web', $seen->guard);
        self::assertTrue($seen->hasScope('anything'), 'Sessions allow every scope.');
        self::assertSame($seen, $during);
        self::assertNull($current->get(), 'Cleared after the request.');
    }

    public function testOptionalAuthenticationLetsGuestsThrough(): void
    {
        $seen = 'not reached';
        $this->through(Authenticate::optional(), $this->request(new Session()), static function (ServerRequestInterface $request) use (&$seen): void {
            $seen = Identity::find($request);
        });
        self::assertNull($seen);

        $this->expectException(UnauthenticatedException::class);
        Identity::of(new ServerRequest('GET', '/'));
    }

    public function testApiGuardsChallengeWithBearerAndCheckScopes(): void
    {
        $this->application->getContainer()->instance('test.header-guard', new HeaderGuardFactory());
        $this->boot(['guards' => ['web' => ['driver' => 'session'], 'api' => ['driver' => 'test.header-guard']]]);

        try {
            $this->through(Authenticate::guard('api'), new ServerRequest('GET', '/api/tasks', ['Authorization' => 'Bearer wrong']));
            self::fail('The token was accepted.');
        } catch (UnauthenticatedException $exception) {
            self::assertSame(['WWW-Authenticate' => 'Bearer error="invalid_token"'], $exception->getHeaders());
        }

        $request = new ServerRequest('GET', '/api/tasks', ['Authorization' => 'Bearer 7']);
        $seen = null;
        $this->through(Authenticate::guard('api'), $request, static function (ServerRequestInterface $request) use (&$seen): void {
            $seen = Identity::of($request);
        }, RequireScopes::of('tasks:read'));
        self::assertInstanceOf(Identity::class, $seen);
        self::assertSame(['tasks:read'], $seen->scopes());

        try {
            $this->through(Authenticate::guard('api'), $request, null, RequireScopes::of('tasks:read', 'tasks:write'));
            self::fail('The missing scope was not noticed.');
        } catch (InsufficientScopeException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertSame(['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="tasks:read tasks:write"'], $exception->getHeaders());
            self::assertSame(['scopes' => ['tasks:read', 'tasks:write']], $exception->details());
        }
    }

    public function testGuestPagesSendSignedInBrowsersHome(): void
    {
        $session = new Session();
        self::assertSame(200, $this->through(Guest::class, $this->request($session))->getStatusCode());
        $this->auth()->login($this->request($session), $this->users->findById(7) ?? self::fail());
        $response = $this->through(Guest::class, $this->request($session));
        self::assertSame([302, '/'], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
    }

    public function testTestsCanActAsAUser(): void
    {
        $auth = $this->auth();
        $user = $this->users->findById(7) ?? self::fail();
        $auth->actingAs($user, 'web', ['tasks:read']);
        $identity = $auth->authenticate(new ServerRequest('GET', '/'));
        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame($user, $identity->user);
        self::assertSame(['tasks:read'], $identity->scopes());
        $auth->actingAs(null);
        $this->expectException(LogicException::class);
        $auth->authenticate(new ServerRequest('GET', '/'));
    }

    public function testGuestsNeverTouchTheUserProvider(): void
    {
        $this->boot(['users' => ['driver' => 'database']]);
        $auth = $this->application->get(AuthManager::class);
        self::assertNull($auth->authenticate($this->request(new Session())), 'No provider is needed for a guest.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('auth.users driver database needs crystoline/xpsr-database: run composer require crystoline/xpsr-database, and add Crystoline\Xpsr\Database\DatabaseServiceRegistry to app.registry.');
        $auth->verify('ada@example.com', 'correct horse');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function missingDrivers(): iterable
    {
        yield 'jwt without its package' => [['guards' => ['api' => ['driver' => 'jwt']]], 'auth.guards.api uses the jwt driver, which needs crystoline/xpsr-auth-jwt: run composer require crystoline/xpsr-auth-jwt, and add Crystoline\Xpsr\Auth\Jwt\JwtServiceRegistry to app.registry.'];
        yield 'token without its package' => [['guards' => ['api' => ['driver' => 'token']]], 'which needs crystoline/xpsr-database'];
        yield 'an unknown driver' => [['guards' => ['api' => ['driver' => 'magic']]], 'auth.guards.api driver "magic" must be session, jwt, token, sanctum, or a service implementing'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('missingDrivers')]
    public function testGuardDriversThatAreNotAvailableAreExplained(array $config, string $message): void
    {
        $this->boot($config + ['users' => ['driver' => 'test.users']]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->application->get(AuthManager::class)->guard('api');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['guard' => 'web'], 'Unknown option auth.guard. Known options: default, guards, providers, users, login_path, home_path, policies, jwt, tokens, sanctum.'];
        yield 'no guards' => [['guards' => []], 'auth.guards must name at least one guard'];
        yield 'guard without driver' => [['guards' => ['web' => []]], 'auth.guards.web must be an array with a "driver"'];
        yield 'unknown default' => [['default' => 'admin'], 'auth.default must name a guard in auth.guards: web, api.'];
        yield 'users without driver' => [['users' => ['table' => 'users']], 'auth.users must be an array with a "driver"'];
        yield 'external login path' => [['login_path' => 'https://evil.example/login'], 'auth.login_path must be a path on this site'];
        yield 'protocol-relative home' => [['home_path' => '//evil.example'], 'auth.home_path must be a path on this site'];
        yield 'missing policy class' => [['policies' => ['App\Task' => 'App\Policies\Missing']], 'App\Policies\Missing was not found'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreExplained(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        AuthOptions::fromArray($config);
    }

    public function testAnUnknownGuardNameIsExplained(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no guard "admin" in auth.guards. Configured guards: web, api.');
        $this->auth()->guard('admin');
    }

    public function testTheSessionGuardNeedsTheSessionMiddleware(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The session guard needs a session');
        $this->auth()->authenticate(new ServerRequest('GET', '/'));
    }

    public function testBearerTokensAreReadFromTheAuthorizationHeader(): void
    {
        self::assertSame('abc.DEF-_~+/=', BearerToken::from(new ServerRequest('GET', '/', ['Authorization' => 'bearer  abc.DEF-_~+/='])));
        foreach (['', 'Basic abc', 'Bearer', 'Bearer a b', 'Bearer a,b'] as $header) {
            self::assertNull(BearerToken::from(new ServerRequest('GET', '/', $header === '' ? [] : ['Authorization' => $header])), $header);
        }
    }

    private function auth(): AuthManager
    {
        $this->boot([]);

        return $this->application->get(AuthManager::class);
    }

    /** @param array<string, mixed> $auth */
    private function boot(array $auth): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $config = $this->application->getConfig();
        $config->set('auth', $auth + ['users' => ['driver' => 'test.users']]);
        $config->set('session', ['store' => 'array']);
        $config->set('app.registry', [FactoryRegistry::class, ConsoleServiceRegistry::class, SessionServiceRegistry::class, AuthServiceRegistry::class]);
        $container = $this->application->getContainer();
        $container->instance('test.users', $this->users);
        $container->instance(ResponseFactoryInterface::class, new Psr17Factory());
        $this->application->boot();
    }

    /** @param array<string, string> $headers */
    private function request(Session $session, string $method = 'GET', string $uri = '/', array $headers = []): ServerRequestInterface
    {
        return (new ServerRequest($method, $uri, $headers))->withAttribute(Session::class, $session);
    }

    /**
     * Run a request through a middleware service (and more middleware) to $action.
     *
     * @param (callable(ServerRequestInterface): void)|null $action
     */
    private function through(string $service, ServerRequestInterface $request, ?callable $action = null, MiddlewareInterface ...$more): ResponseInterface
    {
        $this->boot([]);
        $handler = new class ($action) implements RequestHandlerInterface {
            /** @param (callable(ServerRequestInterface): void)|null $action */
            public function __construct(private $action) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->action !== null) {
                    ($this->action)($request);
                }

                return new Response(200);
            }
        };
        foreach (array_reverse($more) as $middleware) {
            $next = $handler;
            $handler = new class ($middleware, $next) implements RequestHandlerInterface {
                public function __construct(private readonly MiddlewareInterface $middleware, private readonly RequestHandlerInterface $next) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }
        $middleware = $this->application->get($service);
        self::assertInstanceOf(MiddlewareInterface::class, $middleware);

        return $middleware->process($request, $handler);
    }
}

/** A guard for tests: "Authorization: Bearer <user id>" with the scope tasks:read. */
final class HeaderGuardFactory implements GuardFactoryInterface
{
    public function create(string $name, array $config, UserProviderInterface $users): GuardInterface
    {
        return new class ($name, $users) implements GuardInterface {
            public function __construct(private readonly string $name, private readonly UserProviderInterface $users) {}

            public function authenticate(ServerRequestInterface $request): ?Identity
            {
                $token = BearerToken::from($request);
                $user = $token !== null && ctype_digit($token) ? $this->users->findById((int) $token) : null;

                return $user === null ? null : new Identity($user, $this->name, ['tasks:read']);
            }

            public function challenge(ServerRequestInterface $request): string
            {
                return BearerToken::challenge($request);
            }
        };
    }
}
