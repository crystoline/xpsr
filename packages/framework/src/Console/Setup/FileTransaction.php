<?php

declare(strict_types=1);

namespace Crystoline\Console\Setup;

use RuntimeException;

/**
 * Writes files atomically and remembers their previous contents so a failed setup can be undone.
 */
final class FileTransaction
{
    /** @var array<string, string|null> previous contents; null when the file did not exist */
    private array $originals = [];

    public function write(string $path, string $contents): void
    {
        if (is_link($path)) {
            throw new RuntimeException("{$path} is a symbolic link; edit it manually.");
        }
        if (!array_key_exists($path, $this->originals)) {
            $previous = is_file($path) ? file_get_contents($path) : null;
            if ($previous === false) {
                throw new RuntimeException("Could not read {$path}.");
            }
            $this->originals[$path] = $previous;
        }
        self::atomicWrite($path, $contents);
    }

    /** Restore every written file. Returns the paths that could not be restored. */
    public function rollback(): array
    {
        $failed = [];
        foreach (array_reverse($this->originals, true) as $path => $contents) {
            try {
                if ($contents === null) {
                    if (is_file($path) && !@unlink($path)) {
                        $failed[] = $path;
                    }
                } else {
                    self::atomicWrite($path, $contents);
                }
            } catch (RuntimeException) {
                $failed[] = $path;
            }
        }
        $this->originals = [];

        return $failed;
    }

    private static function atomicWrite(string $path, string $contents): void
    {
        // Write beside the target, then rename, so readers never see a partial file.
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new RuntimeException("Could not write {$path}.");
            }
            $mode = is_file($path) ? fileperms($path) : false;
            @chmod($temporary, $mode === false ? 0666 & ~umask() : $mode & 0777);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException("Could not write {$path}.");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
