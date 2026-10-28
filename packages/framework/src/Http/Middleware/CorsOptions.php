<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Middleware;

use InvalidArgumentException;

/**
 * The "cors" configuration (config/cors.php), validated:
 *
 *   allowed_origins    origins allowed to call the application from a browser: exact origins such
 *                      as https://app.example.com, subdomain patterns such as https://*.example.com,
 *                      or "*" for any origin. Empty (the default) turns CORS off.
 *   allowed_methods    methods a preflight request may ask for (default GET, HEAD, POST, PUT, PATCH, DELETE)
 *   allowed_headers    request headers a preflight may ask for, or "*" for whatever it asks for
 *                      (default Content-Type, Authorization, X-Requested-With)
 *   exposed_headers    response headers scripts may read besides the simple ones (default none)
 *   allow_credentials  whether browsers may send cookies and authorization (default false)
 *   max_age            seconds browsers may cache a preflight answer; 0 sends no Max-Age (default 600)
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class CorsOptions
{
    private const ORIGIN = '#\A(https?)://(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)(:\d{1,5})?\z#';
    private const TOKEN = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';

    /**
     * @param list<string> $origins exact origins, lowercase
     * @param list<string> $originPatterns regular expressions for subdomain patterns
     * @param list<string> $methods
     * @param list<string>|null $headers null to allow whatever a preflight asks for
     * @param list<string> $exposedHeaders
     */
    private function __construct(
        public readonly bool $anyOrigin,
        public readonly array $origins,
        public readonly array $originPatterns,
        public readonly array $methods,
        public readonly ?array $headers,
        public readonly array $exposedHeaders,
        public readonly bool $credentials,
        public readonly int $maxAge,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        $known = ['allowed_origins', 'allowed_methods', 'allowed_headers', 'exposed_headers', 'allow_credentials', 'max_age'];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option cors.%s. Known options: %s.', $key, implode(', ', $known)));
            }
        }
        $any = false;
        $origins = $patterns = [];
        foreach (self::list($config, 'allowed_origins', []) as $origin) {
            if ($origin === '*') {
                $any = true;
                continue;
            }
            $origin = strtolower(rtrim($origin, '/'));
            if (!preg_match(self::ORIGIN, $origin, $match)) {
                throw new InvalidArgumentException("cors.allowed_origins: \"{$origin}\" is not an origin such as https://app.example.com or https://*.example.com.");
            }
            if ($match[2] === '') {
                $origins[] = $origin;
            } else {
                $patterns[] = '#\A' . preg_quote($match[1], '#') . '://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.'
                    . preg_quote($match[3] . ($match[7] ?? ''), '#') . '\z#';
            }
        }
        $methods = array_map(strtoupper(...), self::tokens($config, 'allowed_methods', ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']));
        $headers = self::list($config, 'allowed_headers', ['Content-Type', 'Authorization', 'X-Requested-With']);
        $credentials = $config['allow_credentials'] ?? false;
        if (!is_bool($credentials)) {
            throw new InvalidArgumentException('cors.allow_credentials must be true or false.');
        }
        $maxAge = $config['max_age'] ?? 600;
        if (!is_int($maxAge) || $maxAge < 0) {
            throw new InvalidArgumentException('cors.max_age must be a number of seconds, 0 or more.');
        }

        return new self(
            $any,
            $origins,
            $patterns,
            $methods,
            $headers === ['*'] ? null : self::tokens(['allowed_headers' => $headers], 'allowed_headers', []),
            self::tokens($config, 'exposed_headers', []),
            $credentials,
            $maxAge,
        );
    }

    /** Whether CORS is configured at all. */
    public function enabled(): bool
    {
        return $this->anyOrigin || $this->origins !== [] || $this->originPatterns !== [];
    }

    public function allows(string $origin): bool
    {
        if ($this->anyOrigin) {
            return true;
        }
        $origin = strtolower($origin);
        if (in_array($origin, $this->origins, true)) {
            return true;
        }
        foreach ($this->originPatterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $default
     * @return list<string>
     */
    private static function list(array $config, string $key, array $default): array
    {
        $value = $config[$key] ?? $default;
        if (is_string($value)) {
            // Comma-separated, as from an environment variable.
            $value = array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn(string $item): bool => $item !== ''));
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("cors.{$key} must be a list of strings.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException("cors.{$key} must be a list of strings.");
            }
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $default
     * @return list<string>
     */
    private static function tokens(array $config, string $key, array $default): array
    {
        $values = self::list($config, $key, $default);
        foreach ($values as $value) {
            if (!preg_match(self::TOKEN, $value)) {
                throw new InvalidArgumentException("cors.{$key}: \"{$value}\" is not a valid name.");
            }
        }

        return $values;
    }
}
