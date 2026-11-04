<?php

declare(strict_types=1);

namespace Crystoline\Xpsr;

use Illuminate\Config\Repository;
use RuntimeException;

class Configuration extends Repository
{
    public function loadConfigurationFiles(string $path, ?string $environment = null): void
    {
        $this->items = [];

        foreach (self::files($path) as $key => $file) {
            $this->set($key, self::read($file));
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
}
