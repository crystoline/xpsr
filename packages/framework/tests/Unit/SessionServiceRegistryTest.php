<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Console\Commands\SessionPruneCommand;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Crystoline\Xpsr\Registry\SessionServiceRegistry;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\Session\ArraySessionStore;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\CurrentSession;
use Crystoline\Xpsr\Session\FileSessionStore;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionManager;
use Crystoline\Xpsr\Session\SessionMiddleware;
use Crystoline\Xpsr\Session\SessionOptions;
use Crystoline\Xpsr\Session\SessionStoreInterface;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Twig\Error\RuntimeError;

final class SessionServiceRegistryTest extends TestCase
{
    private string $root;
    private Application $application;

    protected function setUp(): void
    {
        $this->root = TemporaryDirectory::create('session app');
        mkdir($this->root . '/config');
        mkdir($this->root . '/views');
        $this->application = new Application($this->root);
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    public function testTheDefaultsAreAFileStoreAndALaxCookie(): void
    {
        $this->boot([]);
        $options = $this->application->get(SessionOptions::class);

        self::assertInstanceOf(FileSessionStore::class, $this->application->get(SessionStoreInterface::class));
        self::assertSame(['file', 7200, 100, 'xpsr_session', '/', null, null, 'Lax', $this->root . '/var/sessions', 'sessions', null], [
            $options->store, $options->lifetime, $options->pruneEvery, $options->cookieName, $options->cookiePath, $options->cookieDomain,
            $options->secure, $options->sameSite, $options->directory, $options->table, $options->connection,
        ]);
        self::assertInstanceOf(SessionMiddleware::class, $this->application->get(SessionMiddleware::class));
        self::assertInstanceOf(CsrfMiddleware::class, $this->application->get(CsrfMiddleware::class));
        self::assertSame($this->application->get(CurrentSession::class), $this->application->get(CurrentSession::class));
        self::assertSame($this->application->get(SessionManager::class)->store(), $this->application->get(SessionStoreInterface::class));
    }

    public function testTheArrayStoreAndCustomStoresCanBeChosen(): void
    {
        $this->boot(['store' => 'array', 'lifetime' => '30 minutes']);
        self::assertInstanceOf(ArraySessionStore::class, $this->application->get(SessionStoreInterface::class));

        $custom = new ArraySessionStore(60, new MockClock());
        $this->application = new Application($this->root);
        $this->application->getContainer()->instance('my.store', $custom);
        $this->boot(['store' => 'my.store']);
        self::assertSame($custom, $this->application->get(SessionStoreInterface::class));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function missingStores(): iterable
    {
        yield 'cache without its package' => [['store' => 'cache'], 'session.store cache needs crystoline/xpsr-cache: run composer require crystoline/xpsr-cache, and add Crystoline\Xpsr\Cache\CacheServiceRegistry to app.registry.'];
        yield 'database without its package' => [['store' => 'database'], 'add Crystoline\Xpsr\Database\DatabaseServiceRegistry to app.registry'];
        // Not "redis": with the phpredis extension loaded, that names its Redis class.
        yield 'an unknown name' => [['store' => 'sessions-in-the-cloud'], '"sessions-in-the-cloud" is none of these'];
        yield 'a service that is not a store' => [['store' => Psr17Factory::class], 'is a service that does not implement Crystoline\Xpsr\Session\SessionStoreInterface. Use file, cache, database, array, or a session store service.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('missingStores')]
    public function testAStoreThatIsNotAvailableIsExplained(array $config, string $message): void
    {
        $this->boot($config);
        try {
            $this->application->get(SessionStoreInterface::class);
            self::fail('The store was resolved.');
        } catch (ContainerException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            self::assertStringContainsString($message, $exception->getPrevious()->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['driver' => 'file'], 'Unknown option session.driver. Known options: store, lifetime, prune_every, cookie, file, database.'];
        yield 'unknown cookie option' => [['cookie' => ['httponly' => false]], 'Unknown option session.cookie.httponly.'];
        yield 'minutes as a number' => [['lifetime' => 120], 'session.lifetime must be a period such as "2 hours"'];
        yield 'not a period' => [['lifetime' => 'forever'], 'session.lifetime must be a period'];
        yield 'negative prune_every' => [['prune_every' => -1], 'session.prune_every'];
        yield 'cookie name' => [['cookie' => ['name' => 'my session']], 'session.cookie.name'];
        yield 'relative path' => [['cookie' => ['path' => 'admin']], 'session.cookie.path must start with "/"'];
        yield 'secure as text' => [['cookie' => ['secure' => 'yes']], 'session.cookie.secure'];
        yield 'lowercase same_site' => [['cookie' => ['same_site' => 'lax']], 'session.cookie.same_site must be Lax, Strict, or None.'];
        yield 'None without secure' => [['cookie' => ['same_site' => 'None']], 'needs session.cookie.secure true'];
        yield 'table name' => [['database' => ['table' => 'sessions; DROP']], 'session.database.table'];
        yield 'empty store' => [['store' => ''], 'session.store must be'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreExplained(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SessionOptions::fromArray($config, '/app');
    }

    public function testAbsoluteAndRelativeFileDirectories(): void
    {
        self::assertSame('/srv/sessions', SessionOptions::fromArray(['file' => ['path' => '/srv/sessions']], '/app')->directory);
        self::assertSame('/app/storage/sessions', SessionOptions::fromArray(['file' => ['path' => 'storage/sessions']], '/app/')->directory);
    }

    public function testThePruneCommandRemovesExpiredSessions(): void
    {
        $clock = new MockClock('2026-10-28 12:00:00');
        $this->application->getContainer()->instance(ClockInterface::class, $clock);
        $this->boot(['store' => 'array', 'lifetime' => '1 hour']);
        $store = $this->application->get(SessionStoreInterface::class);
        $store->write('old', 'x');
        $clock->sleep(3600);

        self::assertArrayHasKey('session:prune', $this->application->get(CommandRegistry::class)->all());
        $tester = new CommandTester($this->application->get(SessionPruneCommand::class));
        self::assertSame(0, $tester->execute([]));
        self::assertSame("Removed 1 expired session(s).\n", $tester->getDisplay());
    }

    public function testViewsGetTheCsrfTokenAndSessionValues(): void
    {
        file_put_contents($this->root . '/views/form.twig', '<form>{{ csrf_field() }}</form> {{ csrf_token() }} {{ session("status", "none") }} {{ session("missing", "none") }}');
        $this->boot(['store' => 'array'], [ViewServiceRegistry::class]);
        $this->application->getConfig()->set('app.view_dir', $this->root . '/views');
        $session = new Session();
        $session->set('status', 'Saved <b>');
        $current = $this->application->get(CurrentSession::class);
        $current->set($session);
        $views = $this->application->get(ViewHandlerInterface::class);

        $token = $session->token();
        self::assertSame(
            "<form><input type=\"hidden\" name=\"_token\" value=\"{$token}\"></form> {$token} Saved &lt;b&gt; none",
            $views->render('form'),
        );

        $current->set(null);
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('csrf_token() and csrf_field() need a session');
        $views->render('form');
    }

    public function testRedirectsUseSeeOtherByDefault(): void
    {
        $responses = new ResponseBuilder(new Psr17Factory(), new Psr17Factory());
        $redirect = $responses->redirect('/tasks');
        self::assertSame([303, '/tasks'], [$redirect->getStatusCode(), $redirect->getHeaderLine('Location')]);
        self::assertSame(308, $responses->redirect('https://example.com/new', 308)->getStatusCode());

        $this->expectException(InvalidArgumentException::class);
        $responses->redirect('/tasks', 200);
    }

    public function testRedirectUrlsCannotSplitHeaders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ResponseBuilder(new Psr17Factory(), new Psr17Factory()))->redirect("/tasks\r\nSet-Cookie: x=1");
    }

    /**
     * @param array<array-key, mixed> $session
     * @param list<class-string> $more
     */
    private function boot(array $session, array $more = []): void
    {
        $config = $this->application->getConfig();
        $config->set('session', $session);
        $config->set('app.registry', [\Crystoline\Xpsr\Registry\FactoryRegistry::class, \Crystoline\Xpsr\Registry\ConsoleServiceRegistry::class, SessionServiceRegistry::class, ...$more]);
        $this->application->boot();
    }
}
