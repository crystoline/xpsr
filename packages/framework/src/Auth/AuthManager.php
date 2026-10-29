<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Crystoline\Xpsr\Session\Session;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signs users in and out and gives access to the configured guards and user providers:
 *
 *     $user = $auth->attempt($request, $email, $password);                  // browser sign-in, default guard
 *     $admin = $auth->attempt($request, $email, $password, guard: 'admin'); // another session guard
 *     $user = $auth->verify($email, $password, guard: 'api');               // credentials only, before issuing a token
 *     $auth->logout($request);
 *
 * Each guard looks its users up in its own provider (auth.guards.<name>.provider), so users and
 * admins can live in separate tables.
 */
final class AuthManager
{
    /** Guard drivers in other packages: driver => [package, registry]. */
    public const DRIVERS = [
        'jwt' => ['crystoline/xpsr-auth-jwt', 'Crystoline\Xpsr\Auth\Jwt\JwtServiceRegistry'],
        'token' => ['crystoline/xpsr-database', 'Crystoline\Xpsr\Database\DatabaseServiceRegistry'],
    ];

    /** User provider drivers in other packages: driver => [package, registry]. */
    public const USER_DRIVERS = [
        'database' => ['crystoline/xpsr-database', 'Crystoline\Xpsr\Database\DatabaseServiceRegistry'],
        'doctrine' => ['crystoline/xpsr-orm-doctrine', 'Crystoline\Xpsr\Orm\Doctrine\DoctrineOrmServiceRegistry'],
        'eloquent' => ['crystoline/xpsr-orm-eloquent', 'Crystoline\Xpsr\Orm\Eloquent\EloquentServiceRegistry'],
    ];

    private const INTENDED = '_intended.';

    /** @var array<string, GuardInterface> */
    private array $guards = [];

    /** @var array<string, UserProviderInterface> */
    private array $providers = [];

    /** @var array{Identity, string|null}|null a test's identity and the guard it applies to (null: all) */
    private ?array $actingAs = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly AuthOptions $options,
        private readonly PasswordHasher $hasher,
        private readonly CurrentIdentity $current,
    ) {}

    public function options(): AuthOptions
    {
        return $this->options;
    }

    public function hasher(): PasswordHasher
    {
        return $this->hasher;
    }

    /** A user provider from auth.providers; the default guard's provider without a name. */
    public function users(?string $provider = null): UserProviderInterface
    {
        $provider ??= $this->options->provider($this->options->default);
        if (isset($this->providers[$provider])) {
            return $this->providers[$provider];
        }
        $config = $this->options->providers[$provider] ?? throw new InvalidArgumentException(sprintf(
            'There is no provider "%s" in auth.providers. Configured providers: %s.',
            $provider,
            implode(', ', array_keys($this->options->providers)),
        ));
        $driver = (string) $config['driver'];
        $id = "auth.users.{$driver}";
        if ($this->container->has($id)) {
            $factory = $this->container->get($id);
            if (!$factory instanceof UserProviderFactoryInterface) {
                throw new LogicException("The service {$id} must implement " . UserProviderFactoryInterface::class . '.');
            }

            return $this->providers[$provider] = $factory->create($config);
        }
        $label = $provider === 'users' && count($this->options->providers) === 1 ? 'auth.users' : "auth.providers.{$provider}";
        if (isset(self::USER_DRIVERS[$driver])) {
            [$package, $registry] = self::USER_DRIVERS[$driver];
            throw new InvalidArgumentException("{$label} driver {$driver} needs {$package}: run composer require {$package}, and add {$registry} to app.registry.");
        }
        $instance = $this->container->has($driver) ? $this->container->get($driver) : null;
        if (!$instance instanceof UserProviderInterface) {
            throw new InvalidArgumentException("{$label} driver \"{$driver}\" must be database, doctrine, eloquent, or a service implementing " . UserProviderInterface::class . '.');
        }

        return $this->providers[$provider] = $instance;
    }

    /** A guard from auth.guards; the default guard without a name. */
    public function guard(?string $name = null): GuardInterface
    {
        $name ??= $this->options->default;
        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }
        $config = $this->options->guards[$name] ?? throw AuthOptions::unknownGuard($name, array_keys($this->options->guards));
        $driver = (string) $config['driver'];
        // Resolved when a guard first looks a user up: guests cost nothing.
        $provider = $this->options->provider($name);
        $users = new LazyUserProvider(fn(): UserProviderInterface => $this->users($provider));
        if ($driver === 'session') {
            return $this->guards[$name] = new SessionGuard($name, $users);
        }
        $id = $this->container->has("auth.driver.{$driver}") ? "auth.driver.{$driver}" : null;
        if ($id === null && isset(self::DRIVERS[$driver])) {
            [$package, $registry] = self::DRIVERS[$driver];
            throw new InvalidArgumentException("auth.guards.{$name} uses the {$driver} driver, which needs {$package}: run composer require {$package}, and add {$registry} to app.registry.");
        }
        $factory = $this->container->has($id ?? $driver) ? $this->container->get($id ?? $driver) : null;
        if (!$factory instanceof GuardFactoryInterface) {
            throw new InvalidArgumentException("auth.guards.{$name} driver \"{$driver}\" must be session, jwt, token, or a service implementing " . GuardFactoryInterface::class . '.');
        }

        return $this->guards[$name] = $factory->create($name, $config, $users);
    }

    /** Who the request proves to be, by one guard; null when nobody. */
    public function authenticate(ServerRequestInterface $request, ?string $guard = null): ?Identity
    {
        $guard ??= $this->options->default;
        if ($this->actingAs !== null && ($this->actingAs[1] === null || $this->actingAs[1] === $guard)) {
            return $this->actingAs[0];
        }

        return $this->guard($guard)->authenticate($request);
    }

    /**
     * The user with this login and password in a guard's provider (the default guard's without
     * a name), or null. Upgrades an outdated password hash. Does not sign anybody in; use it before
     * issuing an API token.
     */
    public function verify(string $login, #[\SensitiveParameter] string $password, ?string $guard = null): ?UserInterface
    {
        $users = $this->users($this->options->provider($guard ?? $this->options->default));
        $user = $users->findByLogin($login);
        $hash = $user?->authPasswordHash();
        if (!$this->hasher->verify($password, $hash) || $user === null || $hash === null) {
            return null;
        }
        if ($this->hasher->needsRehash($hash)) {
            $users->updatePasswordHash($user, $this->hasher->hash($password));
        }

        return $user;
    }

    /**
     * Check a login and password and sign the user in to the request's session on a session
     * guard (the default one without a name); null when they do not match.
     */
    public function attempt(ServerRequestInterface $request, string $login, #[\SensitiveParameter] string $password, ?string $guard = null): ?UserInterface
    {
        $guard = $this->sessionGuard($guard)->name();
        $user = $this->verify($login, $password, $guard);
        if ($user !== null) {
            $this->login($request, $user, $guard);
        }

        return $user;
    }

    /** Sign a user in to the request's session on a session guard, under a new session ID. */
    public function login(ServerRequestInterface $request, UserInterface $user, ?string $guard = null): void
    {
        $this->current->set($this->sessionGuard($guard)->login($request, $user));
    }

    /**
     * Sign a session guard out. When no other session guard is still signed in, the whole session
     * ends; otherwise it only moves to a new ID.
     */
    public function logout(ServerRequestInterface $request, ?string $guard = null): void
    {
        $this->sessionGuard($guard)->logout($request);
        $this->current->set(null);
    }

    /**
     * Where to go after signing in on a guard: the page Authenticate sent the browser away from,
     * or $default (the guard's home_path). Only paths on this site are returned.
     */
    public function intended(ServerRequestInterface $request, ?string $default = null, ?string $guard = null): string
    {
        $guard ??= $this->options->default;
        $session = Session::of($request);
        $path = $session->get(self::INTENDED . $guard);
        $session->remove(self::INTENDED . $guard);

        return is_string($path) && self::isLocalPath($path) ? $path : ($default ?? $this->options->homePath($guard));
    }

    /** @internal Remember the page a browser asked for before being sent to sign in on a guard. */
    public function rememberIntended(ServerRequestInterface $request, ?string $guard = null): void
    {
        $uri = $request->getUri();
        $path = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
        if (self::isLocalPath($path)) {
            Session::of($request)->set(self::INTENDED . ($guard ?? $this->options->default), $path);
        }
    }

    /**
     * @internal For tests: authenticate every request as this user, by one guard or all (null),
     * without credentials. Null stops it.
     *
     * @param list<string>|null $scopes
     */
    public function actingAs(?UserInterface $user, ?string $guard = null, ?array $scopes = null): void
    {
        $this->actingAs = $user === null ? null : [new Identity($user, $guard ?? $this->options->default, $scopes), $guard];
    }

    private function sessionGuard(?string $name): SessionGuard
    {
        if ($name !== null && isset($this->options->guards[$name]) && $this->options->guards[$name]['driver'] !== 'session') {
            throw new LogicException("The guard \"{$name}\" does not use the session driver, so it cannot sign browsers in.");
        }
        $names = $name === null ? $this->options->guardsWithDriver('session') : [$name];
        $guard = $names === [] ? null : $this->guard($names[0]);
        if (!$guard instanceof SessionGuard) {
            throw new LogicException($name === null
                ? 'Signing in to a session needs a guard with the session driver in auth.guards.'
                : "The guard \"{$name}\" does not use the session driver, so it cannot sign browsers in.");
        }

        return $guard;
    }

    private static function isLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\') && !preg_match('/[\x00-\x1F\x7F]/', $path);
    }
}
