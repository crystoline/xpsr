<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\HttpClient;

use InvalidArgumentException;

/**
 * Turns the http_client configuration into Symfony HttpClient options, rejecting unknown or
 * malformed settings with the name of the setting.
 *
 *   timeout        seconds without activity before a request fails (default 10)
 *   max_duration   seconds a whole request may take; 0 for no limit (default 0)
 *   max_redirects  redirects to follow; 0 returns the 3xx response itself (default 5)
 *   headers        headers sent with every request, as name => value or name => list of values
 *   base_uri       named clients only: resolves relative request URLs
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class HttpClientOptions
{
    public const DEFAULTS = ['timeout' => 10, 'max_duration' => 0, 'max_redirects' => 5, 'headers' => []];

    private const NAME = '/\A[A-Za-z0-9_.-]+\z/';

    /**
     * Options for the default client.
     *
     * @param array<array-key, mixed> $config the whole http_client configuration
     * @return array{timeout: float, max_duration: float, max_redirects: int, headers: array<string, list<string>>}
     */
    public static function defaults(array $config): array
    {
        $settings = $config;
        unset($settings['clients']);

        return self::validate([...self::DEFAULTS, ...$settings], 'http_client', allowBaseUri: false);
    }

    /**
     * Options for each named client. Headers are merged with the default client's headers;
     * a header set on the named client replaces the default value.
     *
     * @param array<array-key, mixed> $config the whole http_client configuration
     * @return array<string, array{timeout: float, max_duration: float, max_redirects: int, headers: array<string, list<string>>, base_uri?: string}>
     */
    public static function named(array $config): array
    {
        $clients = $config['clients'] ?? [];
        if (!is_array($clients)) {
            throw new InvalidArgumentException('http_client.clients must map client names to option arrays.');
        }
        $defaults = self::defaults($config);
        $named = [];
        foreach ($clients as $name => $options) {
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException('http_client.clients names may contain only letters, digits, "_", ".", and "-".');
            }
            if (!is_array($options)) {
                throw new InvalidArgumentException("http_client.clients.{$name} must be an array of options.");
            }
            $client = self::validate([...$defaults, 'headers' => [], ...$options], "http_client.clients.{$name}", allowBaseUri: true);
            $client['headers'] = self::mergeHeaders($defaults['headers'], $client['headers']);
            $named[$name] = $client;
        }

        return $named;
    }

    /** The container service ID of a named client: "http_client.github". */
    public static function serviceId(string $name): string
    {
        return 'http_client.' . $name;
    }

    /**
     * @param array<array-key, mixed> $options
     * @return array{timeout: float, max_duration: float, max_redirects: int, headers: array<string, list<string>>, base_uri?: string}
     */
    private static function validate(array $options, string $path, bool $allowBaseUri): array
    {
        $known = [...array_keys(self::DEFAULTS), ...($allowBaseUri ? ['base_uri'] : [])];
        foreach (array_keys($options) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option %s.%s. Known options: %s.', $path, $key, implode(', ', $known)));
            }
        }
        $timeout = $options['timeout'];
        if (!is_int($timeout) && !is_float($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException("{$path}.timeout must be a number of seconds greater than 0.");
        }
        $duration = $options['max_duration'];
        if (!is_int($duration) && !is_float($duration) || $duration < 0) {
            throw new InvalidArgumentException("{$path}.max_duration must be a number of seconds (0 for no limit).");
        }
        $redirects = $options['max_redirects'];
        if (!is_int($redirects) || $redirects < 0) {
            throw new InvalidArgumentException("{$path}.max_redirects must be a whole number, 0 or more.");
        }
        $result = [
            'timeout' => (float) $timeout,
            'max_duration' => (float) $duration,
            'max_redirects' => $redirects,
            'headers' => self::headers($options['headers'], $path),
        ];
        if (isset($options['base_uri'])) {
            $uri = $options['base_uri'];
            if (!is_string($uri) || !preg_match('#\Ahttps?://[^/?\#\s]+#i', $uri) || filter_var($uri, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException("{$path}.base_uri must be an absolute http:// or https:// URL.");
            }
            $result['base_uri'] = $uri;
        }

        return $result;
    }

    /** @return array<string, list<string>> */
    private static function headers(mixed $headers, string $path): array
    {
        if (!is_array($headers)) {
            throw new InvalidArgumentException("{$path}.headers must map header names to values.");
        }
        $result = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name)) {
                throw new InvalidArgumentException("{$path}.headers has an invalid header name.");
            }
            $values = is_array($value) ? $value : [$value];
            if ($values === [] || !array_is_list($values)) {
                throw new InvalidArgumentException("{$path}.headers.{$name} must be a string or a list of strings.");
            }
            foreach ($values as $item) {
                if (!is_string($item) || preg_match('/[\r\n\0]/', $item)) {
                    throw new InvalidArgumentException("{$path}.headers.{$name} must be a string or a list of strings without line breaks.");
                }
            }
            $result[$name] = $values;
        }

        return $result;
    }

    /**
     * @param array<string, list<string>> $defaults
     * @param array<string, list<string>> $overrides
     * @return array<string, list<string>>
     */
    private static function mergeHeaders(array $defaults, array $overrides): array
    {
        $lower = array_change_key_case($overrides);
        $merged = array_filter($defaults, static fn(string $name): bool => !isset($lower[strtolower($name)]), ARRAY_FILTER_USE_KEY);

        return [...$merged, ...$overrides];
    }
}
