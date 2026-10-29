<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use InvalidArgumentException;

/**
 * The "auth" configuration (config/auth.php), validated:
 *
 *   default      the guard Authenticate and Guest use when none is named (default web)
 *   guards       name => ['driver' => session|jwt|token|sanctum|<service>, 'provider' => <name>,
 *                'login_path' => ..., 'home_path' => ...] (default: web on the session, api on jwt)
 *   providers    name => ['driver' => database|doctrine|eloquent|<service>, ...]: where each
 *                kind of user is looked up, such as users and admins
 *   users        the short form for a single provider named "users", when providers is not set
 *   login_path   where Authenticate sends browsers that are not signed in (default /login)
 *   home_path    where Guest sends browsers that are signed in (default /)
 *   policies     model class => policy class
 *   jwt, tokens, sanctum  options for the jwt, token, and sanctum drivers, read by their packages
 *
 * A guard without a provider uses "users", or the only provider there is.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class AuthOptions
{
    private const KNOWN = ['default', 'guards', 'providers', 'users', 'login_path', 'home_path', 'policies', 'jwt', 'tokens', 'sanctum'];
    private const NAME = '/\A[A-Za-z0-9_.-]+\z/';

    /**
     * @param array<string, array<array-key, mixed>> $guards
     * @param array<string, array<array-key, mixed>> $providers
     * @param array<string, string> $guardProviders guard => provider
     * @param array<string, array{string, string}> $paths guard => [login path, home path]
     * @param array<class-string, class-string> $policies
     * @param array<array-key, mixed> $jwt
     * @param array<array-key, mixed> $tokens
     * @param array<array-key, mixed> $sanctum
     */
    private function __construct(
        public readonly string $default,
        public readonly array $guards,
        public readonly array $providers,
        private readonly array $guardProviders,
        private readonly array $paths,
        public readonly array $policies,
        public readonly array $jwt,
        public readonly array $tokens,
        public readonly array $sanctum,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option auth.%s. Known options: %s.', $key, implode(', ', self::KNOWN)));
            }
        }
        $providers = self::providers($config);
        $guards = $config['guards'] ?? ['web' => ['driver' => 'session'], 'api' => ['driver' => 'jwt']];
        if (!is_array($guards) || $guards === []) {
            throw new InvalidArgumentException('auth.guards must name at least one guard, such as \'web\' => [\'driver\' => \'session\'].');
        }
        $defaultPaths = [self::path($config, 'login_path', 'auth', '/login'), self::path($config, 'home_path', 'auth', '/')];
        $guardProviders = $paths = [];
        foreach ($guards as $name => $guard) {
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException('auth.guards names must be letters, digits, dots, underscores, or hyphens.');
            }
            if (!is_array($guard) || !is_string($guard['driver'] ?? null) || $guard['driver'] === '') {
                throw new InvalidArgumentException("auth.guards.{$name} must be an array with a \"driver\": session, jwt, token, sanctum, or a service.");
            }
            $provider = $guard['provider'] ?? (isset($providers['users']) || count($providers) !== 1 ? 'users' : array_key_first($providers));
            if (!is_string($provider) || !isset($providers[$provider])) {
                throw new InvalidArgumentException(sprintf(
                    'auth.guards.%s.provider must name a provider in auth.providers: %s.',
                    $name,
                    implode(', ', array_keys($providers)),
                ));
            }
            $guardProviders[$name] = $provider;
            $paths[$name] = [
                self::path($guard, 'login_path', "auth.guards.{$name}", $defaultPaths[0]),
                self::path($guard, 'home_path', "auth.guards.{$name}", $defaultPaths[1]),
            ];
        }
        /** @var array<string, array<array-key, mixed>> $guards */
        $default = $config['default'] ?? array_key_first($guards);
        if (!is_string($default) || !isset($guards[$default])) {
            throw new InvalidArgumentException('auth.default must name a guard in auth.guards: ' . implode(', ', array_keys($guards)) . '.');
        }
        $policies = $config['policies'] ?? [];
        if (!is_array($policies)) {
            throw new InvalidArgumentException('auth.policies must map model classes to policy classes.');
        }
        foreach ($policies as $model => $policy) {
            if (!is_string($model) || !is_string($policy) || !class_exists($policy)) {
                throw new InvalidArgumentException('auth.policies must map model classes to existing policy classes; ' . (is_string($policy) ? "{$policy} was not found" : 'a policy is not a class name') . '.');
            }
        }
        foreach (['jwt', 'tokens', 'sanctum'] as $section) {
            if (isset($config[$section]) && !is_array($config[$section])) {
                throw new InvalidArgumentException("auth.{$section} must be an array of options.");
            }
        }

        /** @var array<class-string, class-string> $policies */
        return new self($default, $guards, $providers, $guardProviders, $paths, $policies, $config['jwt'] ?? [], $config['tokens'] ?? [], $config['sanctum'] ?? []);
    }

    /** The provider a guard looks its users up in. */
    public function provider(string $guard): string
    {
        return $this->guardProviders[$guard] ?? throw self::unknownGuard($guard, array_keys($this->guards));
    }

    /** Where Authenticate sends a guest of this guard (the default guard without a name). */
    public function loginPath(?string $guard = null): string
    {
        return $this->paths[$guard ?? $this->default][0] ?? throw self::unknownGuard((string) $guard, array_keys($this->guards));
    }

    /** Where Guest sends a user signed in on this guard. */
    public function homePath(?string $guard = null): string
    {
        return $this->paths[$guard ?? $this->default][1] ?? throw self::unknownGuard((string) $guard, array_keys($this->guards));
    }

    /** @return list<string> the guards using a driver, the default guard first */
    public function guardsWithDriver(string $driver): array
    {
        $names = array_keys(array_filter($this->guards, static fn(array $guard): bool => ($guard['driver'] ?? null) === $driver));
        usort($names, fn(string $a, string $b): int => ($b === $this->default) <=> ($a === $this->default));

        return $names;
    }

    /** @param list<string> $guards */
    public static function unknownGuard(string $guard, array $guards): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('There is no guard "%s" in auth.guards. Configured guards: %s.', $guard, implode(', ', $guards)));
    }

    /**
     * @param array<array-key, mixed> $config
     * @return array<string, array<array-key, mixed>>
     */
    private static function providers(array $config): array
    {
        if (isset($config['providers'], $config['users'])) {
            throw new InvalidArgumentException('Set auth.providers or auth.users, not both: auth.users is the short form for a single provider named "users".');
        }
        $providers = $config['providers'] ?? ['users' => $config['users'] ?? ['driver' => 'database']];
        if (!is_array($providers) || $providers === []) {
            throw new InvalidArgumentException('auth.providers must name at least one provider, such as \'users\' => [\'driver\' => \'database\'].');
        }
        foreach ($providers as $name => $provider) {
            $label = isset($config['providers']) ? "auth.providers.{$name}" : 'auth.users';
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException('auth.providers names must be letters, digits, dots, underscores, or hyphens.');
            }
            if (!is_array($provider) || !is_string($provider['driver'] ?? null) || $provider['driver'] === '') {
                throw new InvalidArgumentException("{$label} must be an array with a \"driver\": database, doctrine, eloquent, or a service implementing " . UserProviderInterface::class . '.');
            }
        }

        /** @var array<string, array<array-key, mixed>> $providers */
        return $providers;
    }

    /** @param array<array-key, mixed> $config */
    private static function path(array $config, string $key, string $prefix, string $fallback): string
    {
        $path = $config[$key] ?? $fallback;
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException("{$prefix}.{$key} must be a path on this site, such as {$fallback}.");
        }

        return $path;
    }
}
