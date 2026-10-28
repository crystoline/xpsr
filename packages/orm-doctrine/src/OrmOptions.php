<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine;

use InvalidArgumentException;

/**
 * The "orm" configuration (config/orm.php), validated:
 *
 *   connection      name from database.connections; null for the default connection
 *   entities        directories of attribute-mapped entity classes, relative to the project root
 *                   (default app/Entity, skipped while it does not exist)
 *   metadata_cache  "auto" (the application's PSR-6 cache when installed and APP_DEBUG is off,
 *                   otherwise memory), "array" (memory), or the service ID of a PSR-6 pool
 *   naming          "underscore" (default: property emailAddress maps to column email_address)
 *                   or "default" (columns named exactly like properties)
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class OrmOptions
{
    public const DEFAULT_ENTITIES = 'app/Entity';

    /** @param list<string> $entityPaths absolute directories */
    private function __construct(
        public readonly ?string $connection,
        public readonly array $entityPaths,
        public readonly string $metadataCache,
        public readonly bool $underscoreNaming,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function from(array $config, string $rootDir): self
    {
        $known = ['connection', 'entities', 'metadata_cache', 'naming'];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option orm.%s. Known options: %s.', $key, implode(', ', $known)));
            }
        }
        $connection = $config['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new InvalidArgumentException('orm.connection must name a connection from database.connections, or be null for the default.');
        }
        $cache = $config['metadata_cache'] ?? 'auto';
        if (!is_string($cache) || $cache === '') {
            throw new InvalidArgumentException('orm.metadata_cache must be "auto", "array", or the service ID of a PSR-6 cache pool.');
        }
        $naming = $config['naming'] ?? 'underscore';
        if ($naming !== 'underscore' && $naming !== 'default') {
            throw new InvalidArgumentException('orm.naming must be "underscore" or "default".');
        }

        return new self($connection, self::entityPaths($config, $rootDir), $cache, $naming === 'underscore');
    }

    /**
     * @param array<array-key, mixed> $config
     * @return list<string>
     */
    private static function entityPaths(array $config, string $rootDir): array
    {
        if (!array_key_exists('entities', $config)) {
            $default = $rootDir . '/' . self::DEFAULT_ENTITIES;

            return is_dir($default) ? [$default] : [];
        }
        $entities = $config['entities'];
        if (!is_array($entities) || !array_is_list($entities)) {
            throw new InvalidArgumentException('orm.entities must be a list of directories.');
        }
        $paths = [];
        foreach ($entities as $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidArgumentException('orm.entities must be a list of directories.');
            }
            $absolute = str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:[/\\\\]#', $path) === 1 ? $path : $rootDir . '/' . $path;
            if (!is_dir($absolute)) {
                throw new InvalidArgumentException("The entity directory {$path} in orm.entities does not exist.");
            }
            $paths[] = $absolute;
        }

        return $paths;
    }
}
