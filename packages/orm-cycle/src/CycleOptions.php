<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use InvalidArgumentException;

/**
 * The "cycle" configuration (config/cycle.php), validated:
 *
 *   connection    the name from database.connections that is Cycle's default database and the
 *                 one make:migration --diff compares; null for the default connection
 *   entities      directories of attribute-mapped entity classes, relative to the project root
 *                 (default app/Entity, skipped while it does not exist)
 *   schema_cache  "auto" (default): the schema php xpsr cycle:schema:cache compiled, from
 *                 var/cache/cycle-schema.php, when the file exists and APP_DEBUG is off, otherwise
 *                 compiled from the entities on each request; a path names another file, used
 *                 whenever it exists; null compiles on each request, always
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class CycleOptions
{
    public const DEFAULT_ENTITIES = 'app/Entity';
    public const DEFAULT_CACHE = 'var/cache/cycle-schema.php';
    private const KNOWN = ['connection', 'entities', 'schema_cache'];

    /**
     * @param list<string> $entityPaths absolute directories
     * @param string|null $cacheFile the schema cache's full path; null for no cache
     * @param bool $cacheAuto whether the cache is used only with APP_DEBUG off
     */
    private function __construct(
        public readonly ?string $connection,
        public readonly array $entityPaths,
        public readonly ?string $cacheFile,
        public readonly bool $cacheAuto,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function from(array $config, string $rootDir): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option cycle.%s. Known options: %s.', $key, implode(', ', self::KNOWN)));
            }
        }
        $connection = $config['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new InvalidArgumentException('cycle.connection must name a connection from database.connections, or be null for the default.');
        }
        $cache = array_key_exists('schema_cache', $config) ? $config['schema_cache'] : 'auto';
        if ($cache !== null && (!is_string($cache) || $cache === '')) {
            throw new InvalidArgumentException('cycle.schema_cache must be "auto", the path of a file, or null.');
        }
        $file = match (true) {
            $cache === null => null,
            $cache === 'auto' => self::path($rootDir, self::DEFAULT_CACHE),
            default => self::path($rootDir, $cache),
        };

        return new self($connection, self::entityPaths($config, $rootDir), $file, $cache === 'auto');
    }

    /**
     * @param array<array-key, mixed> $config
     * @return list<string>
     */
    private static function entityPaths(array $config, string $rootDir): array
    {
        if (!array_key_exists('entities', $config)) {
            $default = self::path($rootDir, self::DEFAULT_ENTITIES);

            return is_dir($default) ? [$default] : [];
        }
        $entities = $config['entities'];
        if (!is_array($entities) || !array_is_list($entities)) {
            throw new InvalidArgumentException('cycle.entities must be a list of directories, relative to the project root.');
        }
        $paths = [];
        foreach ($entities as $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidArgumentException('cycle.entities must be a list of directories, relative to the project root.');
            }
            $directory = self::path($rootDir, $path);
            if (!is_dir($directory)) {
                throw new InvalidArgumentException("cycle.entities: the directory {$path} does not exist.");
            }
            $paths[] = $directory;
        }

        return $paths;
    }

    private static function path(string $rootDir, string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($rootDir, '/') . '/' . $path;
    }
}
