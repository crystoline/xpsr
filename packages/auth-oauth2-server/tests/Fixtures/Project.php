<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\InMemoryUserProvider;
use Crystoline\Xpsr\Auth\OAuth2\Clients;
use Crystoline\Xpsr\Auth\OAuth2\Console\MakeOAuthTablesCommand;
use Crystoline\Xpsr\Auth\OAuth2\OAuth2ServiceRegistry;
use Crystoline\Xpsr\Auth\OAuth2\Tokens;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\RouteServiceRegistry;
use Crystoline\Xpsr\Registry\SessionServiceRegistry;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A temporary project with in-memory users, SQLite, the OAuth2 tables from the package's own
 * migration, a key pair made once for the process, the authorize and token routes, and an API
 * behind the oauth2 guard.
 */
final class Project
{
    public const SCOPES = ['orders:read' => 'Read your orders', 'orders:write' => 'Create and change orders', 'profile' => 'See your name and email address'];

    public readonly string $root;
    public readonly Application $application;
    public readonly MockClock $clock;
    public readonly InMemoryUserProvider $users;
    public readonly GenericUser $ada;

    /** @var array{string, string, string}|null the private key, the public key, and the encryption key, made once for the process */
    private static ?array $keys = null;

    /**
     * @param array<string, mixed> $oauth the "oauth" configuration, over the fixture's scopes and encryption key
     * @param bool $keys with the key files in var/oauth
     * @param bool $views with the view provider and a consent view of the application's own
     * @param bool $tables with the tables, from the package's migration
     */
    public function __construct(array $oauth = [], bool $keys = true, bool $views = false, private readonly bool $tables = true)
    {
        $this->root = sys_get_temp_dir() . '/xpsr-oauth2-' . bin2hex(random_bytes(6));
        foreach (['config', 'routes', 'migrations', 'var/oauth', 'views/oauth'] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }
        [$private, $public, $encryption] = self::keys();
        if ($keys) {
            file_put_contents($this->root . '/var/oauth/private.key', $private);
            file_put_contents($this->root . '/var/oauth/public.key', $public);
            chmod($this->root . '/var/oauth/private.key', 0600);
            chmod($this->root . '/var/oauth/public.key', 0600);
        }
        if ($views) {
            file_put_contents(
                $this->root . '/views/oauth/authorize.twig',
                '<h1>Custom consent: {{ client.name }} for {{ user.get("name") }}</h1>{% for scope in scopes %}<p>{{ scope.description }}</p>{% endfor %}'
                . '<form method="post" action="{{ action }}"><input type="hidden" name="_token" value="{{ csrf_token }}"><button name="action" value="approve">Yes</button></form>' . "\n",
            );
        }
        file_put_contents($this->root . '/routes/web.php', <<<'PHP'
            <?php
            use Crystoline\Xpsr\Auth\Http\Authenticate;
            use Crystoline\Xpsr\Auth\Http\RequireScopes;
            use Crystoline\Xpsr\Auth\OAuth2\Http\AuthorizeController;
            use Crystoline\Xpsr\Auth\OAuth2\Http\TokenController;
            use Crystoline\Xpsr\Auth\OAuth2\Tests\Fixtures\Controllers;
            use Crystoline\Xpsr\Routing\RouteCollection;
            use Crystoline\Xpsr\Session\CsrfMiddleware;
            use Crystoline\Xpsr\Session\SessionMiddleware;

            return static function (RouteCollection $routes): void {
                $routes->get('/oauth/authorize', [AuthorizeController::class, 'show'])->middleware(SessionMiddleware::class, CsrfMiddleware::class, Authenticate::class);
                $routes->post('/oauth/authorize', [AuthorizeController::class, 'decide'])->middleware(SessionMiddleware::class, CsrfMiddleware::class, Authenticate::class);
                $routes->post('/oauth/token', [TokenController::class, 'token']);
                $routes->get('/api/me', [Controllers::class, 'me'])->middleware(Authenticate::guard('api'));
                $routes->get('/api/orders', [Controllers::class, 'orders'])->middleware(Authenticate::guard('api'), RequireScopes::of('orders:read'));
            };
            PHP);
        $this->clock = FixtureRegistry::$clock = new MockClock('now', 'UTC');
        $this->users = FixtureRegistry::$users = new InMemoryUserProvider();
        $this->ada = new GenericUser(7, null, ['name' => 'Ada', 'email' => 'ada@example.com']);
        $this->users->add($this->ada, 'ada@example.com');
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.name', 'Shop');
        $config->set('app.env', 'local');
        $config->set('app.debug', true);
        $config->set('app.url', 'http://localhost');
        $config->set('app.view_dir', $this->root . '/views');
        $config->set('app.view_engine', 'twig');
        $config->set('app.middleware', []);
        $registry = [
            FixtureRegistry::class,
            RouteServiceRegistry::class,
            ConsoleServiceRegistry::class,
            SessionServiceRegistry::class,
            AuthServiceRegistry::class,
        ];
        if ($views) {
            $registry[] = ViewServiceRegistry::class;
        }
        $registry[] = DatabaseServiceRegistry::class;
        $registry[] = OAuth2ServiceRegistry::class;
        $config->set('app.registry', $registry);
        $config->set('routing', ['adapter' => 'fastroute', 'files' => ['routes/web.php']]);
        $config->set('session', ['store' => 'array']);
        $config->set('auth', ['users' => ['driver' => 'test.users'], 'guards' => ['web' => ['driver' => 'session'], 'api' => ['driver' => 'oauth2']]]);
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
            'migrations' => ['path' => 'migrations'],
        ]);
        $config->set('oauth', $oauth + ['scopes' => self::SCOPES, 'encryption_key' => $encryption]);
    }

    /** Boots the application and, unless the project is without tables, creates them from the package's migration. */
    public function boot(): Application
    {
        $this->application->boot();
        if ($this->tables) {
            $tester = new CommandTester($this->application->get(MakeOAuthTablesCommand::class));
            if ($tester->execute([]) !== 0) {
                throw new RuntimeException('make:oauth-tables failed: ' . $tester->getDisplay());
            }
            $this->application->get(MigratorFactory::class)->create()->migrate();
        }

        return $this->application;
    }

    /** Every request from now on is the user's (null: a guest's), on every guard. */
    public function actingAs(?UserInterface $user, ?string $guard = null): void
    {
        $this->application->get(AuthManager::class)->actingAs($user, $guard);
    }

    public function clients(): Clients
    {
        return $this->application->get(Clients::class);
    }

    public function tokens(): Tokens
    {
        return $this->application->get(Tokens::class);
    }

    public function db(): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection();
    }

    /** @return array{string, string, string} the private key, the public key, and the encryption key */
    public static function keys(): array
    {
        if (self::$keys === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($resource === false || !openssl_pkey_export($resource, $private)) {
                throw new RuntimeException('Could not generate a key pair: ' . openssl_error_string());
            }
            $details = openssl_pkey_get_details($resource);
            if (!is_array($details) || !is_string($details['key'] ?? null)) {
                throw new RuntimeException('Could not read the public key.');
            }
            self::$keys = [(string) $private, $details['key'], Key::createNewRandomKey()->saveToAsciiSafeString()];
        }

        return self::$keys;
    }

    public function remove(): void
    {
        $this->application->get(ConnectionManager::class)->connection()->close();
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
