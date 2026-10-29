<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use InvalidArgumentException;

/**
 * The "auth" configuration (config/auth.php), validated:
 *
 *   default      the guard Authenticate uses when none is named (default web)
 *   guards       name => ['driver' => session|jwt|token|<service>, ...] (default: web on the
 *                session, api on jwt)
 *   users        ['driver' => database|doctrine|<service>, ...]: where users are looked up
 *   login_path   where Authenticate sends browsers that are not signed in (default /login)
 *   home_path    where Guest sends browsers that are signed in (default /)
 *   policies     model class => policy class
 *   jwt, tokens  options for the jwt and token drivers, read by their packages
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class AuthOptions
{
    private const KNOWN = ['default', 'guards', 'users', 'login_path', 'home_path', 'policies', 'jwt', 'tokens'];
    private const NAME = '/\A[A-Za-z0-9_.-]+\z/';

    /**
     * @param array<string, array<array-key, mixed>> $guards
     * @param array<array-key, mixed> $users
     * @param array<class-string, class-string> $policies
     * @param array<array-key, mixed> $jwt
     * @param array<array-key, mixed> $tokens
     */
    private function __construct(
        public readonly string $default,
        public readonly array $guards,
        public readonly array $users,
        public readonly string $loginPath,
        public readonly string $homePath,
        public readonly array $policies,
        public readonly array $jwt,
        public readonly array $tokens,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option auth.%s. Known options: %s.', $key, implode(', ', self::KNOWN)));
            }
        }
        $guards = $config['guards'] ?? ['web' => ['driver' => 'session'], 'api' => ['driver' => 'jwt']];
        if (!is_array($guards) || $guards === []) {
            throw new InvalidArgumentException('auth.guards must name at least one guard, such as \'web\' => [\'driver\' => \'session\'].');
        }
        foreach ($guards as $name => $guard) {
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException('auth.guards names must be letters, digits, dots, underscores, or hyphens.');
            }
            if (!is_array($guard) || !is_string($guard['driver'] ?? null) || $guard['driver'] === '') {
                throw new InvalidArgumentException("auth.guards.{$name} must be an array with a \"driver\": session, jwt, token, or a service.");
            }
        }
        /** @var array<string, array<array-key, mixed>> $guards */
        $default = $config['default'] ?? array_key_first($guards);
        if (!is_string($default) || !isset($guards[$default])) {
            throw new InvalidArgumentException('auth.default must name a guard in auth.guards: ' . implode(', ', array_keys($guards)) . '.');
        }
        $users = $config['users'] ?? ['driver' => 'database'];
        if (!is_array($users) || !is_string($users['driver'] ?? null) || $users['driver'] === '') {
            throw new InvalidArgumentException('auth.users must be an array with a "driver": database, doctrine, or a service implementing ' . UserProviderInterface::class . '.');
        }
        $paths = [];
        foreach (['login_path' => '/login', 'home_path' => '/'] as $key => $fallback) {
            $path = $config[$key] ?? $fallback;
            if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
                throw new InvalidArgumentException("auth.{$key} must be a path on this site, such as {$fallback}.");
            }
            $paths[$key] = $path;
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
        foreach (['jwt', 'tokens'] as $section) {
            if (isset($config[$section]) && !is_array($config[$section])) {
                throw new InvalidArgumentException("auth.{$section} must be an array of options.");
            }
        }

        /** @var array<class-string, class-string> $policies */
        return new self(
            $default,
            $guards,
            $users,
            $paths['login_path'],
            $paths['home_path'],
            $policies,
            $config['jwt'] ?? [],
            $config['tokens'] ?? [],
        );
    }
}
