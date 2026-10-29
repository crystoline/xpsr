<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Crystoline\Xpsr\Session\Session;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signs users in and out and gives access to the configured guards and user provider:
 *
 *     $user = $auth->attempt($request, $email, $password);   // browser sign-in, on the session
 *     $user = $auth->verify($email, $password);              // check credentials only, for issuing API tokens
 *     $auth->logout($request);
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

    private const INTENDED = '_auth.intended';

    /** @var array<string, GuardInterface> */
    private array $guards = [];

    private ?UserProviderInterface $users = null;

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

    /** The user provider configured in auth.users. */
    public function users(): UserProviderInterface
    {
        if ($this->users !== null) {
            return $this->users;
        }
        $config = $this->options->users;
        $driver = (string) $config['driver'];
        $id = "auth.users.{$driver}";
        if ($this->container->has($id)) {
            $factory = $this->container->get($id);
            if (!$factory instanceof UserProviderFactoryInterface) {
                throw new LogicException("The service {$id} must implement " . UserProviderFactoryInterface::class . '.');
            }

            return $this->users = $factory->create($config);
        }
        if (isset(self::USER_DRIVERS[$driver])) {
            [$package, $registry] = self::USER_DRIVERS[$driver];
            throw new InvalidArgumentException("auth.users driver {$driver} needs {$package}: run composer require {$package}, and add {$registry} to app.registry.");
        }
        $provider = $this->container->has($driver) ? $this->container->get($driver) : null;
        if (!$provider instanceof UserProviderInterface) {
            throw new InvalidArgumentException("auth.users driver \"{$driver}\" must be database, doctrine, eloquent, or a service implementing " . UserProviderInterface::class . '.');
        }

        return $this->users = $provider;
    }

    /** A guard from auth.guards; the default guard without a name. */
    public function guard(?string $name = null): GuardInterface
    {
        $name ??= $this->options->default;
        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }
        $config = $this->options->guards[$name] ?? throw new InvalidArgumentException(sprintf(
            'There is no guard "%s" in auth.guards. Configured guards: %s.',
            $name,
            implode(', ', array_keys($this->options->guards)),
        ));
        $driver = (string) $config['driver'];
        // Resolved when a guard first looks a user up: guests cost nothing.
        $users = new LazyUserProvider($this->users(...));
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
     * The user with this login and password, or null. Upgrades an outdated password hash. Does not
     * sign anybody in; use it before issuing an API token.
     */
    public function verify(string $login, #[\SensitiveParameter] string $password): ?UserInterface
    {
        $user = $this->users()->findByLogin($login);
        $hash = $user?->authPasswordHash();
        if (!$this->hasher->verify($password, $hash) || $user === null || $hash === null) {
            return null;
        }
        if ($this->hasher->needsRehash($hash)) {
            $this->users()->updatePasswordHash($user, $this->hasher->hash($password));
        }

        return $user;
    }

    /** Check a login and password and sign the user in to the request's session; null when they do not match. */
    public function attempt(ServerRequestInterface $request, string $login, #[\SensitiveParameter] string $password): ?UserInterface
    {
        $user = $this->verify($login, $password);
        if ($user !== null) {
            $this->login($request, $user);
        }

        return $user;
    }

    /** Sign a user in to the request's session, under a new session ID. */
    public function login(ServerRequestInterface $request, UserInterface $user): void
    {
        $this->current->set($this->sessionGuard()->login($request, $user));
    }

    /** Sign the request's session out and end it. */
    public function logout(ServerRequestInterface $request): void
    {
        $this->sessionGuard()->logout($request);
        $this->current->set(null);
    }

    /**
     * Where to go after signing in: the page Authenticate sent the browser away from, or $default
     * (auth.home_path). Only paths on this site are returned.
     */
    public function intended(ServerRequestInterface $request, ?string $default = null): string
    {
        $session = Session::of($request);
        $path = $session->get(self::INTENDED);
        $session->remove(self::INTENDED);

        return is_string($path) && self::isLocalPath($path) ? $path : ($default ?? $this->options->homePath);
    }

    /** @internal Remember the page a browser asked for before being sent to sign in. */
    public function rememberIntended(ServerRequestInterface $request): void
    {
        $uri = $request->getUri();
        $path = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
        if (self::isLocalPath($path)) {
            Session::of($request)->set(self::INTENDED, $path);
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

    private function sessionGuard(): SessionGuard
    {
        $names = array_keys($this->options->guards);
        usort($names, fn(string $a, string $b): int => ($b === $this->options->default) <=> ($a === $this->options->default));
        foreach ($names as $name) {
            if (($this->options->guards[$name]['driver'] ?? null) === 'session') {
                $guard = $this->guard($name);
                if ($guard instanceof SessionGuard) {
                    return $guard;
                }
            }
        }
        throw new LogicException('Signing in to a session needs a guard with the session driver in auth.guards.');
    }

    private static function isLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\') && !preg_match('/[\x00-\x1F\x7F]/', $path);
    }
}
