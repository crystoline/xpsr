<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Generator;

use InvalidArgumentException;
use LogicException;

/**
 * Renders XPSR stubs into application classes.
 *
 * Class names map to PSR-4 paths below the application source directory. Writes never
 * leave that directory, including through symbolic links, and never replace an existing
 * file unless forced.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class StubGenerator
{
    private const SEGMENT = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /** PHP keywords and reserved type names that cannot name a class, plus Windows device names. */
    private const RESERVED = [
        '__halt_compiler', 'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif',
        'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval',
        'exit', 'extends', 'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global',
        'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'int', 'interface',
        'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null', 'numeric',
        'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require',
        'require_once', 'resource', 'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true',
        'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
        'aux', 'con', 'nul', 'prn',
    ];

    private readonly string $rootDir;
    private readonly string $namespace;
    private readonly string $sourceDir;
    private readonly string $stubDir;

    public function __construct(string $rootDir, string $namespace = 'App', string $sourceDir = 'app', ?string $stubDir = null)
    {
        $namespace = trim($namespace, '\\');
        $this->segments($namespace, 'The application namespace');
        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        if (!preg_match('#\A[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*\z#', $sourceDir)) {
            throw new InvalidArgumentException('The application source directory must be a relative path inside the project, such as "app".');
        }
        $this->rootDir = rtrim($rootDir, '/\\');
        $this->namespace = $namespace;
        $this->sourceDir = $sourceDir;
        $this->stubDir = $stubDir ?? dirname(__DIR__) . '/Resources/stubs';
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getSourceDir(): string
    {
        return $this->sourceDir;
    }

    /**
     * Validate a class name relative to a namespace below the application namespace.
     *
     * Both "Admin/UserController" and "Admin\UserController" are accepted.
     */
    public function resolve(string $subNamespace, string $name): GeneratedClass
    {
        $segments = $this->segments(str_replace('/', '\\', trim($name)), 'The class name');
        $base = $subNamespace === '' ? [] : explode('\\', $subNamespace);
        $shortName = array_pop($segments);
        $relativePath = implode('/', [$this->sourceDir, ...$base, ...$segments, $shortName . '.php']);

        return new GeneratedClass(
            implode('\\', [$this->namespace, ...$base, ...$segments]),
            $shortName,
            $this->rootDir . '/' . $relativePath,
            $relativePath,
        );
    }

    /**
     * Render a stub into the target file.
     *
     * @param array<string, string> $replacements values for {{ name }} placeholders
     * @return bool true when an existing file was replaced
     */
    public function write(GeneratedClass $target, string $stub, array $replacements = [], bool $force = false): bool
    {
        $contents = $this->render($stub, ['namespace' => $target->namespace, 'class' => $target->shortName] + $replacements);
        $boundary = realpath($this->rootDir . '/' . $this->sourceDir);
        if ($boundary === false || !is_dir($boundary)) {
            throw new GeneratorException("The application source directory {$this->sourceDir}/ does not exist.");
        }

        $directory = dirname($target->path);
        $this->assertInside($this->nearestExisting($directory), $boundary, $target);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new GeneratorException("Could not create the directory for {$target->relativePath}.");
        }
        $this->assertInside($directory, $boundary, $target);

        if (is_link($target->path)) {
            throw new GeneratorException("{$target->relativePath} is a symbolic link; refusing to replace it.");
        }
        if (is_dir($target->path)) {
            throw new GeneratorException("{$target->relativePath} is a directory.");
        }
        if (file_exists($target->path)) {
            if (!$force) {
                throw new GeneratorException("{$target->relativePath} already exists. Use --force to replace it.");
            }
            $this->replace($target, $contents);

            return true;
        }

        // Exclusive creation: a file created by someone else in the meantime is never overwritten.
        $this->create($target->path, $contents, $target);

        return false;
    }

    /** @param array<string, string> $values */
    private function render(string $stub, array $values): string
    {
        if (!preg_match('/\A[a-z][a-z.-]*\z/', $stub)) {
            throw new LogicException("Invalid stub name \"{$stub}\".");
        }
        $template = @file_get_contents($this->stubDir . '/' . $stub . '.stub');
        if ($template === false) {
            throw new GeneratorException("Generator stub \"{$stub}\" was not found.");
        }
        preg_match_all('/\{\{ ([a-z_]+) \}\}/', $template, $matches);
        $missing = array_diff(array_unique($matches[1]), array_keys($values));
        if ($missing !== []) {
            throw new LogicException("Stub \"{$stub}\" has no value for: " . implode(', ', $missing) . '.');
        }
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs['{{ ' . $key . ' }}'] = $value;
        }

        return strtr($template, $pairs);
    }

    /** @return non-empty-list<string> */
    private function segments(string $name, string $label): array
    {
        if ($name === '') {
            throw new InvalidArgumentException("{$label} cannot be empty.");
        }
        $segments = explode('\\', $name);
        foreach ($segments as $segment) {
            if (!preg_match(self::SEGMENT, $segment)) {
                throw new InvalidArgumentException(
                    "{$label} \"{$name}\" is not valid. Use letters, digits, and underscores, start each part with a letter or underscore, and separate parts with / or \\.",
                );
            }
            if (in_array(strtolower($segment), self::RESERVED, true) || preg_match('/\A(?:com|lpt)[1-9]\z/i', $segment)) {
                throw new InvalidArgumentException("{$label} \"{$name}\" uses the reserved name \"{$segment}\".");
            }
        }

        return $segments;
    }

    private function nearestExisting(string $directory): string
    {
        while (!file_exists($directory) && !is_link($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return $directory;
    }

    private function assertInside(string $path, string $boundary, GeneratedClass $target): void
    {
        $real = realpath($path);
        $prefix = rtrim($boundary, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($real === false || ($real !== $boundary && !str_starts_with($real, $prefix))) {
            throw new GeneratorException("Refusing to write {$target->relativePath}: it resolves outside {$this->sourceDir}/.");
        }
    }

    private function create(string $path, string $contents, GeneratedClass $target): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (file_exists($path) || is_link($path)) {
                throw new GeneratorException("{$target->relativePath} already exists. Use --force to replace it.");
            }
            throw new GeneratorException("Could not create {$target->relativePath}.");
        }
        try {
            $written = fwrite($handle, $contents);
        } finally {
            fclose($handle);
        }
        if ($written !== strlen($contents)) {
            @unlink($path);
            throw new GeneratorException("Could not write {$target->relativePath}.");
        }
    }

    private function replace(GeneratedClass $target, string $contents): void
    {
        // Write beside the target, then rename, so an interrupted write never leaves a partial class.
        $temporary = dirname($target->path) . '/.' . $target->shortName . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $this->create($temporary, $contents, $target);
            @chmod($temporary, 0666 & ~umask());
            if (!@rename($temporary, $target->path)) {
                throw new GeneratorException("Could not replace {$target->relativePath}.");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
