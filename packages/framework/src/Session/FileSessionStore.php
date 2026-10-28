<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Keeps each session in a file named after its ID, readable only by the owner. The file's
 * modification time is its last write, so a session expires once that is older than the lifetime.
 *
 * Writes go to a temporary file that is then renamed, so a reader never sees half a session.
 * Concurrent requests for one session are not locked: the last one to finish writes its values.
 */
final class FileSessionStore implements SessionStoreInterface
{
    private const ID = '/\A[A-Za-z0-9_-]{1,128}\z/';
    private const PREFIX = 'sess_';

    /** @param int $lifetime seconds a session lasts without being written */
    public function __construct(
        private readonly string $directory,
        private readonly int $lifetime,
        private readonly ClockInterface $clock,
    ) {
        if ($lifetime < 1) {
            throw new InvalidArgumentException('The session lifetime must be at least 1 second.');
        }
    }

    public function read(string $id): ?string
    {
        $file = $this->path($id);
        clearstatcache(true, $file);
        $modified = @filemtime($file);
        if ($modified === false || $this->expired($modified)) {
            return null;
        }
        $payload = @file_get_contents($file);

        return $payload === false ? null : $payload;
    }

    public function write(string $id, string $payload): void
    {
        $file = $this->path($id);
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Could not create the session directory {$this->directory}.");
        }
        // tempnam() creates the file with mode 0600.
        $temporary = @tempnam($this->directory, 'tmp_');
        if ($temporary === false || @file_put_contents($temporary, $payload) !== strlen($payload)) {
            if ($temporary !== false) {
                @unlink($temporary);
            }
            throw new RuntimeException("Could not write a session file in {$this->directory}. Check that the web server can write there.");
        }
        @touch($temporary, $this->clock->now()->getTimestamp());
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write the session file {$file}.");
        }
    }

    public function destroy(string $id): void
    {
        @unlink($this->path($id));
    }

    public function prune(): int
    {
        $removed = 0;
        foreach (glob($this->directory . '/' . self::PREFIX . '*', GLOB_NOSORT) ?: [] as $file) {
            clearstatcache(true, $file);
            $modified = @filemtime($file);
            if ($modified !== false && $this->expired($modified) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function expired(int $modified): bool
    {
        return $modified + $this->lifetime <= $this->clock->now()->getTimestamp();
    }

    private function path(string $id): string
    {
        if (!preg_match(self::ID, $id)) {
            throw new InvalidArgumentException('A session ID must be 1 to 128 letters, digits, underscores, or hyphens.');
        }

        return $this->directory . '/' . self::PREFIX . $id;
    }
}
