<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use LogicException;
use RuntimeException;

/**
 * The compiled schema in a file, written by php xpsr cycle:schema:cache and read instead of
 * compiling the entities on each request.
 *
 * @internal
 */
final class SchemaCache
{
    /** @param string|null $file the file's full path; null when there is no cache */
    public function __construct(private readonly ?string $file) {}

    public function file(): ?string
    {
        return $this->file;
    }

    public function exists(): bool
    {
        return $this->file !== null && is_file($this->file);
    }

    /** @return array<string, array<int, mixed>>|null the cached schema, or null when there is none */
    public function load(): ?array
    {
        if ($this->file === null || !is_file($this->file)) {
            return null;
        }
        $schema = require $this->file;

        /** @var array<string, array<int, mixed>>|null */
        return is_array($schema) ? $schema : null;
    }

    /** @param array<string, array<int, mixed>> $schema */
    public function write(array $schema): void
    {
        if ($this->file === null) {
            throw new LogicException('cycle.schema_cache is null, so there is no cache file to write.');
        }
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create the directory {$directory}.");
        }
        $contents = "<?php\n\n// The Cycle ORM schema, compiled by php xpsr cycle:schema:cache. Run it again after changing entities.\n\nreturn " . var_export($schema, true) . ";\n";
        $temporary = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $contents) !== strlen($contents) || !@rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write {$this->file}.");
        }
    }

    /** Remove the cached schema; false when there was none. */
    public function clear(): bool
    {
        return $this->exists() && $this->file !== null && unlink($this->file);
    }
}
