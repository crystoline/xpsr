<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api;

use InvalidArgumentException;

/**
 * The "api" configuration (config/api.php, optional), validated:
 *
 *   wrap          the key a resource or collection is put under in the response body (default:
 *                 data); null for a bare body, except where pagination links and meta need the
 *                 envelope
 *   per_page      items per page when the request does not ask for a number (default: 15)
 *   max_per_page  the most a request may ask for with per_page; larger requests get this many
 *                 (default: 100)
 */
final class ApiOptions
{
    private const KEYS = ['wrap', 'per_page', 'max_per_page'];

    public function __construct(
        public readonly ?string $wrap = 'data',
        public readonly int $perPage = 15,
        public readonly int $maxPerPage = 100,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("Unknown option api.{$key}. Known options: " . implode(', ', self::KEYS) . '.');
            }
        }
        $wrap = array_key_exists('wrap', $config) ? $config['wrap'] : 'data';
        if ($wrap !== null && (!is_string($wrap) || $wrap === '')) {
            throw new InvalidArgumentException('api.wrap must be the key for the response body, such as "data", or null for no envelope.');
        }
        $perPage = self::count($config['per_page'] ?? 15, 'api.per_page');
        $maxPerPage = self::count($config['max_per_page'] ?? 100, 'api.max_per_page');
        if ($maxPerPage < $perPage) {
            throw new InvalidArgumentException("api.max_per_page ({$maxPerPage}) must be at least api.per_page ({$perPage}).");
        }

        return new self($wrap, $perPage, $maxPerPage);
    }

    private static function count(mixed $value, string $what): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$what} must be a whole number of items, one or more.");
        }

        return $value;
    }
}
