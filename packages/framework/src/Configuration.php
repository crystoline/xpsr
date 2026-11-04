<?php

declare(strict_types=1);

namespace Crystoline\Xpsr;

use RuntimeException;

/**
 * The application's configuration: the arrays the files in config/ return, by file name, read with
 * dot notation ("app.name"). A key is looked up as a whole first and then segment by segment, so
 * a top-level key that contains a dot is still found. The default of get() applies when the key is
 * missing; a key set to null returns null, as has() reports it present.
 */
class Configuration
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = []) {}

    public function has(string $key): bool
    {
        return $this->find($key)[0];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->find($key);

        return $found ? $value : $default;
    }

    /** Sets a value, creating the arrays on the way; a scalar on the way is replaced by an array. */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $target = &$this->items;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target[$last] = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function loadConfigurationFiles(string $path, ?string $environment = null): void
    {
        $this->items = [];

        foreach (self::files($path) as $key => $file) {
            $this->items[$key] = self::read($file);
        }

        if ($environment === null || $environment === '') {
            return;
        }

        foreach (self::files($path . '/' . $environment) as $key => $file) {
            foreach (self::read($file) as $setting => $value) {
                $this->set($key . '.' . $setting, $value);
            }
        }
    }

    /**
     * The configuration files directly in a directory, by key (the file name without .php) in
     * name order; none when the directory does not exist.
     *
     * @return array<string, string> key => path
     */
    public static function files(string $path): array
    {
        $files = [];

        foreach (glob($path . '/*.php') ?: [] as $file) {
            if (is_file($file)) {
                $files[basename($file, '.php')] = $file;
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * The array a configuration file returns.
     *
     * @return array<string, mixed>
     */
    public static function read(string $file): array
    {
        $values = (static fn(string $path): mixed => require $path)($file);

        if (!is_array($values)) {
            throw new RuntimeException(sprintf('Configuration file "%s" must return an array.', $file));
        }

        /** @var array<string, mixed> $values */
        return $values;
    }

    /** @return array{bool, mixed} whether the key is present, and its value */
    private function find(string $key): array
    {
        if (array_key_exists($key, $this->items)) {
            return [true, $this->items[$key]];
        }
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }
}
