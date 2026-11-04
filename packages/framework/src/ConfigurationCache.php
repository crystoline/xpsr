<?php

declare(strict_types=1);

namespace Crystoline\Xpsr;

use RuntimeException;
use UnitEnum;

/**
 * The configuration cache, var/cache/config.php: the merged configuration with env() resolved,
 * written by config:cache and read on each request in place of the files. A section whose file
 * holds a closure or an object, such as the skeleton's config/factory.php, cannot be exported, so
 * it is read from its file on each request and merged in. The cache records a hash of every file
 * it was built from, and of .env, and the application sets the cache aside when one of them no
 * longer matches, so a changed file or variable is never hidden by an old value.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ConfigurationCache
{
    public const PATH = 'var/cache/config.php';
    private const ALGORITHM = 'xxh128';
    private const COMMAND = 'php xpsr config:cache';

    public function __construct(private readonly string $root) {}

    public function path(): string
    {
        return $this->root . '/' . self::PATH;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Builds the cache for an environment (APP_ENV) from the configuration files.
     *
     * @return array{cached: list<string>, live: list<string>} the sections cached, and those read from their files
     */
    public function build(string $environment): array
    {
        $configuration = new Configuration();
        $configuration->loadConfigurationFiles($this->root . '/config', $environment);
        $items = $cached = $live = [];
        foreach ($configuration->all() as $key => $values) {
            if (self::exportable($values)) {
                $items[$key] = $values;
                $cached[] = (string) $key;
            } else {
                $live[] = (string) $key;
            }
        }
        $files = $sources = [];
        foreach ($this->directories($environment) as $name => $directory) {
            $files[$name] = array_keys(Configuration::files($directory));
            foreach (Configuration::files($directory) as $file) {
                $sources[$file] = hash_file(self::ALGORITHM, $file);
            }
        }
        if (is_file($this->root . '/.env')) {
            $sources[$this->root . '/.env'] = hash_file(self::ALGORITHM, $this->root . '/.env');
        }
        $this->write(['environment' => $environment, 'files' => $files, 'sources' => $sources, 'live' => $live, 'items' => $items]);

        return ['cached' => $cached, 'live' => $live];
    }

    /**
     * The configuration from the cache, or null when there is none.
     *
     * @throws StaleConfigurationCacheException when the cache was built for another environment or
     *                                          from files that have changed since; the files are
     *                                          the truth then
     */
    public function load(string $environment): ?Configuration
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        $cache = (static fn(string $file): mixed => require $file)($path);
        if (!is_array($cache) || !is_string($cache['environment'] ?? null) || !is_array($cache['files'] ?? null)
            || !is_array($cache['sources'] ?? null) || !is_array($cache['live'] ?? null) || !is_array($cache['items'] ?? null)) {
            throw new StaleConfigurationCacheException(self::PATH . ' is not a configuration cache. ' . $this->advice());
        }
        if ($cache['environment'] !== $environment) {
            throw new StaleConfigurationCacheException(sprintf('%s was built for APP_ENV=%s, but APP_ENV is %s. %s', self::PATH, $cache['environment'], $environment, $this->advice()));
        }
        foreach ($this->directories($environment) as $name => $directory) {
            $now = array_keys(Configuration::files($directory));
            $then = $cache['files'][$name] ?? [];
            $then = is_array($then) ? array_values(array_filter($then, 'is_string')) : [];
            if ($now !== $then) {
                $changed = [...array_diff($now, $then), ...array_diff($then, $now)];
                throw $this->stale($name . '/' . $changed[0] . '.php');
            }
        }
        foreach ($cache['sources'] as $file => $hash) {
            if (!is_string($file) || !is_file($file) || hash_file(self::ALGORITHM, $file) !== $hash) {
                throw $this->stale((string) $file);
            }
        }
        /** @var array<string, mixed> $items */
        $items = $cache['items'];
        $configuration = new Configuration($items);
        foreach ($cache['live'] as $key) {
            if (!is_string($key)) {
                continue;
            }
            $base = $this->root . '/config/' . $key . '.php';
            if (is_file($base)) {
                $configuration->set($key, Configuration::read($base));
            }
            $override = $this->root . '/config/' . $environment . '/' . $key . '.php';
            if (is_file($override)) {
                foreach (Configuration::read($override) as $setting => $value) {
                    $configuration->set($key . '.' . $setting, $value);
                }
            }
        }

        return $configuration;
    }

    /** Removes the cache; false when there was none. */
    public function clear(): bool
    {
        $path = $this->path();
        if (!is_file($path)) {
            return false;
        }
        if (!@unlink($path)) {
            throw new RuntimeException("Could not remove {$path}.");
        }
        self::forget($path);

        return true;
    }

    /** Scalars, null, enum cases, and arrays of them can be written with var_export(); closures and other objects cannot. */
    private static function exportable(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::exportable($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value) || $value instanceof UnitEnum;
    }

    /** @param array<string, mixed> $cache */
    private function write(array $cache): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}.");
        }
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $contents = "<?php\n\n// Written by " . self::COMMAND . "; config:clear removes it. Do not edit.\nreturn " . var_export($cache, true) . ";\n";
        if (@file_put_contents($temporary, $contents) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write {$path}.");
        }
        self::forget($path);
    }

    /** OPcache keeps the old file otherwise, until its timestamps are checked or PHP is reloaded. */
    private static function forget(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /** @return array<string, string> directory as shown => directory path */
    private function directories(string $environment): array
    {
        return ['config' => $this->root . '/config', 'config/' . $environment => $this->root . '/config/' . $environment];
    }

    private function stale(string $file): StaleConfigurationCacheException
    {
        $shown = str_starts_with($file, $this->root . '/') ? substr($file, strlen($this->root) + 1) : $file;

        return new StaleConfigurationCacheException(sprintf('%s no longer matches %s. %s', self::PATH, $shown, $this->advice()));
    }

    private function advice(): string
    {
        return 'The configuration files were used instead; run "' . self::COMMAND . '" again, or "php xpsr config:clear".';
    }
}
