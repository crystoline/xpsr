<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\InMemoryUserProvider;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Broadcast\BroadcastServiceRegistry;
use Crystoline\Xpsr\Broadcast\Http\SubscriptionsMiddleware;
use Crystoline\Xpsr\Broadcast\Subscriptions;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Crystoline\Xpsr\Registry\RouteServiceRegistry;
use Crystoline\Xpsr\Registry\SessionServiceRegistry;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\Session\SessionMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Clock\MockClock;

/**
 * A temporary project with a fake HTTP client standing in for the hub, in-memory users, Twig
 * views using hub_url(), routes that publish in and out of transactions and dispatch a
 * broadcastable event, SQLite, the sync queue, and the broadcast provider.
 */
final class Project
{
    public const HUB = 'http://hub.internal:3000/.well-known/mercure';
    public const PUBLIC_HUB = 'https://hub.test/.well-known/mercure';
    public const SECRET = '!ChangeThisMercureHubJWTSecretKey!';

    public readonly string $root;
    public readonly Application $application;
    public readonly MockClock $clock;
    public readonly FakeClient $client;
    public readonly InMemoryUserProvider $users;
    public readonly GenericUser $ada;
    private readonly bool $database;

    /**
     * @param array<string, mixed> $broadcast the "broadcast" configuration, over the fixture's hub, secret, and subscriptions
     * @param bool $middleware with SubscriptionsMiddleware in app.middleware
     * @param bool $queue with the queue provider (sync transport)
     * @param bool $database with the database provider
     */
    public function __construct(array $broadcast = [], bool $middleware = true, bool $queue = true, bool $database = true)
    {
        $this->database = $database;
        $this->root = sys_get_temp_dir() . '/xpsr-broadcast-' . bin2hex(random_bytes(6));
        foreach (['config', 'routes', 'views', 'var'] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }
        file_put_contents($this->root . '/views/page.twig', "<div data-hub=\"{{ hub_url(['orders/' ~ order]) }}\"></div>\n");
        file_put_contents($this->root . '/views/public.twig', "{{ hub_url('news', false) }}\n");
        file_put_contents($this->root . '/routes/web.php', <<<'PHP'
            <?php
            use Crystoline\Xpsr\Auth\Http\Authenticate;
            use Crystoline\Xpsr\Broadcast\Http\SubscriptionsController;
            use Crystoline\Xpsr\Broadcast\Tests\Fixtures\Controllers;
            use Crystoline\Xpsr\Routing\RouteCollection;

            return static function (RouteCollection $routes): void {
                $routes->get('/page', [Controllers::class, 'page'])->middleware(Authenticate::optional());
                $routes->get('/public', [Controllers::class, 'publicPage']);
                $routes->get('/broadcast/authorize', [SubscriptionsController::class, 'authorize'])->middleware(Authenticate::optional());
                $routes->post('/pay/{id}', [Controllers::class, 'pay']);
                $routes->post('/publish', [Controllers::class, 'publishNow']);
                $routes->post('/event', [Controllers::class, 'event']);
                $routes->post('/logout', [Controllers::class, 'logout']);
            };
            PHP);
        $this->clock = FixtureRegistry::$clock = new MockClock('2026-11-03 10:00:00', 'UTC');
        $this->client = FixtureRegistry::$client = new FakeClient();
        $this->users = FixtureRegistry::$users = new InMemoryUserProvider();
        $this->ada = new GenericUser(7, null, ['name' => 'Ada']);
        $this->users->add($this->ada, 'ada@example.com');
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.name', 'Shop');
        $config->set('app.env', 'local');
        $config->set('app.debug', true);
        $config->set('app.view_dir', $this->root . '/views');
        $config->set('app.view_engine', 'twig');
        $config->set('app.middleware', $middleware ? [SessionMiddleware::class, SubscriptionsMiddleware::class] : [SessionMiddleware::class]);
        $registry = [
            FixtureRegistry::class,
            RouteServiceRegistry::class,
            EventServiceRegistry::class,
            ConsoleServiceRegistry::class,
            SessionServiceRegistry::class,
            AuthServiceRegistry::class,
            ViewServiceRegistry::class,
        ];
        if ($database) {
            $registry[] = DatabaseServiceRegistry::class;
        }
        if ($queue) {
            $registry[] = QueueServiceRegistry::class;
        }
        $registry[] = BroadcastServiceRegistry::class;
        $config->set('app.registry', $registry);
        $config->set('routing', ['adapter' => 'fastroute', 'files' => ['routes/web.php']]);
        $config->set('session', ['store' => 'array']);
        $config->set('auth', ['users' => ['driver' => 'test.users']]);
        $config->set('database', ['default' => 'main', 'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']]]);
        $config->set('queue', ['transports' => ['default' => ['dsn' => 'sync://']]]);
        $config->set('broadcast', $broadcast + [
            'hub' => self::HUB,
            'public_hub' => self::PUBLIC_HUB,
            'secret' => self::SECRET,
            'subscriptions' => static fn(UserInterface $user): array => ["users/{$user->authIdentifier()}/{+rest}", 'announcements'],
        ]);
    }

    public function boot(): Application
    {
        $this->application->boot();

        return $this->application;
    }

    /** Every request from now on is the user's (null: a guest's). */
    public function actingAs(?UserInterface $user): void
    {
        $this->application->get(AuthManager::class)->actingAs($user);
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $uri, array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, 'https://shop.example' . $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->application->getHttpKernel()->handle($request);
    }

    /** A hub that accepts every update, answering an ID. */
    public function hubAccepts(int $updates = 1): void
    {
        $factory = new Psr17Factory();
        for ($i = 0; $i < $updates; $i++) {
            $this->client->queue($factory->createResponse(200)->withBody($factory->createStream("urn:uuid:" . bin2hex(random_bytes(4)))));
        }
    }

    public function subscriptions(): Subscriptions
    {
        return $this->application->get(Subscriptions::class);
    }

    public function remove(): void
    {
        if ($this->database) {
            $this->application->get(ConnectionManager::class)->connection()->close();
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
