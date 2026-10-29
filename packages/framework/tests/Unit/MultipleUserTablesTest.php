<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\Access\Gate;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\AuthOptions;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\Http\Authenticate;
use Crystoline\Xpsr\Auth\Http\Guest;
use Crystoline\Xpsr\Auth\InMemoryUserProvider;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Crystoline\Xpsr\Auth\SessionGuard;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\FactoryRegistry;
use Crystoline\Xpsr\Registry\SessionServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
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

/** Users and admins in separate tables: a provider per guard, and a sign-in per session guard. */
final class MultipleUserTablesTest extends TestCase
{
    private string $root;
    private Application $application;

    protected function setUp(): void
    {
        $this->root = TemporaryDirectory::create('auth tables');
        mkdir($this->root . '/config');
        $this->application = new Application($this->root);
        $hasher = new PasswordHasher();
        $users = new InMemoryUserProvider();
        $users->add(new GenericUser(7, $hasher->hash('user secret'), ['name' => 'Ada']), login: 'ada@example.com');
        $admins = new InMemoryUserProvider();
        $admins->add(new AdminFixture(7, $hasher->hash('admin secret')), login: 'root@example.com');

        $config = $this->application->getConfig();
        $config->set('auth', [
            'guards' => [
                'web' => ['driver' => 'session', 'provider' => 'users'],
                'admin' => ['driver' => 'session', 'provider' => 'admins', 'login_path' => '/admin/login', 'home_path' => '/admin'],
            ],
            'providers' => ['users' => ['driver' => 'test.users'], 'admins' => ['driver' => 'test.admins']],
        ]);
        $config->set('session', ['store' => 'array']);
        $config->set('app.registry', [FactoryRegistry::class, ConsoleServiceRegistry::class, SessionServiceRegistry::class, AuthServiceRegistry::class]);
        $container = $this->application->getContainer();
        $container->instance('test.users', $users);
        $container->instance('test.admins', $admins);
        $container->instance(ResponseFactoryInterface::class, new Psr17Factory());
        $this->application->boot();
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    public function testEachGuardLooksInItsOwnProvider(): void
    {
        $auth = $this->auth();
        self::assertNull($auth->verify('root@example.com', 'admin secret'), 'The default guard\'s provider has no admins.');
        self::assertInstanceOf(AdminFixture::class, $auth->verify('root@example.com', 'admin secret', guard: 'admin'));
        self::assertNull($auth->verify('ada@example.com', 'user secret', guard: 'admin'));
        self::assertInstanceOf(GenericUser::class, $auth->users('users')->findById(7));
        self::assertInstanceOf(AdminFixture::class, $auth->users('admins')->findById(7), 'The same ID is a different person in each table.');
    }

    public function testOneBrowserCanBeSignedInOnBothGuardsAndOutOfEither(): void
    {
        $auth = $this->auth();
        $session = new Session();
        $request = (new ServerRequest('GET', '/'))->withAttribute(Session::class, $session);
        self::assertNotNull($auth->attempt($request, 'ada@example.com', 'user secret'));
        self::assertNotNull($auth->attempt($request, 'root@example.com', 'admin secret', guard: 'admin'));
        self::assertInstanceOf(GenericUser::class, $auth->authenticate($request)?->user);
        self::assertInstanceOf(AdminFixture::class, $auth->authenticate($request, 'admin')?->user);
        self::assertSame('admin', $auth->guard('admin')->authenticate($request)?->guard);

        $session->set('cart', [1]);
        $before = $session->id();
        $auth->logout($request, 'admin');
        self::assertNull($auth->authenticate($request, 'admin'));
        self::assertNotNull($auth->authenticate($request), 'The user stays signed in.');
        self::assertSame([1], $session->get('cart'));
        self::assertNotSame($before, $session->id(), 'The session moved to a new ID.');

        $auth->logout($request);
        self::assertSame([], $session->all(), 'Signing the last guard out ends the session.');
        self::assertFalse($session->has(SessionGuard::key('web')));
    }

    public function testEachGuardHasItsOwnLoginAndHomePages(): void
    {
        $session = new Session();
        $request = (new ServerRequest('GET', '/admin/users'))->withAttribute(Session::class, $session);
        $refused = $this->through(Authenticate::guard('admin'), $request);
        self::assertSame([302, '/admin/login'], [$refused->getStatusCode(), $refused->getHeaderLine('Location')]);
        $account = (new ServerRequest('GET', '/account'))->withAttribute(Session::class, $session);
        self::assertSame('/login', $this->through(Authenticate::class, $account)->getHeaderLine('Location'));

        $auth = $this->auth();
        self::assertSame('/account', $auth->intended($request), 'Each guard remembers its own page.');
        self::assertSame('/admin/users', $auth->intended($request, guard: 'admin'));

        $auth->login($request, $auth->users('admins')->findById(7) ?? self::fail(), 'admin');
        $guest = $this->through(Guest::guard('admin'), $request);
        self::assertSame([302, '/admin'], [$guest->getStatusCode(), $guest->getHeaderLine('Location')]);
        self::assertSame(200, $this->through(Guest::class, $request)->getStatusCode(), 'Still a guest on the web guard.');
    }

    public function testPoliciesTypedForAdminsRefuseUsers(): void
    {
        $gate = new Gate(new ServiceContainer());
        $gate->define('manage-users', static fn(AdminFixture $admin): bool => true);
        self::assertTrue($gate->allows(new AdminFixture(1, null), 'manage-users'));
        self::assertFalse($gate->allows(new GenericUser(1, null), 'manage-users'), 'A user is refused, not a TypeError.');
        $gate->define('view', static fn(AdminFixture|GenericUser $user): bool => true);
        self::assertTrue($gate->allows(new GenericUser(1, null), 'view'));
    }

    public function testOnlySessionGuardsSignBrowsersIn(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The guard "admin-api" does not use the session driver');
        $options = AuthOptions::fromArray(['guards' => ['web' => ['driver' => 'session'], 'admin-api' => ['driver' => 'test.none']]]);
        (new AuthManager(new ServiceContainer(), $options, new PasswordHasher(), new \Crystoline\Xpsr\Auth\CurrentIdentity()))
            ->login(new ServerRequest('GET', '/'), new GenericUser(1, null), 'admin-api');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidProviders(): iterable
    {
        yield 'both forms' => [['users' => ['driver' => 'database'], 'providers' => ['users' => ['driver' => 'database']]], 'Set auth.providers or auth.users, not both'];
        yield 'unknown provider' => [['providers' => ['users' => ['driver' => 'database']], 'guards' => ['web' => ['driver' => 'session', 'provider' => 'admins']]], 'auth.guards.web.provider must name a provider in auth.providers: users.'];
        yield 'provider without driver' => [['providers' => ['admins' => ['table' => 'admins']]], 'auth.providers.admins must be an array with a "driver"'];
        yield 'external guard login path' => [['guards' => ['web' => ['driver' => 'session', 'login_path' => 'https://evil.example']]], 'auth.guards.web.login_path must be a path on this site'];
        yield 'no default provider' => [['providers' => ['admins' => ['driver' => 'database'], 'staff' => ['driver' => 'database']]], 'auth.guards.web.provider must name a provider in auth.providers: admins, staff.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidProviders')]
    public function testInvalidProvidersAreExplained(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        AuthOptions::fromArray($config);
    }

    public function testASingleProviderNeedsNoName(): void
    {
        $options = AuthOptions::fromArray(['providers' => ['admins' => ['driver' => 'database']], 'guards' => ['admin' => ['driver' => 'session']]]);
        self::assertSame('admins', $options->provider('admin'));
        self::assertSame(['admin'], $options->guardsWithDriver('session'));
    }

    private function auth(): AuthManager
    {
        return $this->application->get(AuthManager::class);
    }

    private function through(string $service, ServerRequestInterface $request): ResponseInterface
    {
        $middleware = $this->application->get($service);
        self::assertInstanceOf(MiddlewareInterface::class, $middleware);

        return $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        });
    }
}

/** An admin, from another table than users. */
final class AdminFixture implements UserInterface
{
    public function __construct(private readonly int $id, private readonly ?string $hash) {}

    public function authIdentifier(): int
    {
        return $this->id;
    }

    public function authPasswordHash(): ?string
    {
        return $this->hash;
    }
}
