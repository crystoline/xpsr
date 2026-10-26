<?php

declare(strict_types=1);

namespace Crystoline\Console\Setup;

use InvalidArgumentException;

/**
 * Sets keys in .env contents while keeping every other line, comment, and line ending.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class EnvironmentFile
{
    /** @param array<string, string> $values */
    public static function set(string $contents, array $values): string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = $contents === '' ? [] : (array) preg_split('/\r?\n/', $contents);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        foreach ($values as $key => $value) {
            if (!preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $key)) {
                throw new InvalidArgumentException("Invalid environment variable name \"{$key}\".");
            }
            if (!preg_match('#\A[A-Za-z0-9_.:/-]*\z#', $value)) {
                throw new InvalidArgumentException("The value for {$key} must not need quoting.");
            }
            $found = false;
            foreach ($lines as $index => $line) {
                if (preg_match('/\A(\s*(?:export\s+)?)' . $key . '\s*=/', (string) $line, $match)) {
                    $lines[$index] = $match[1] . $key . '=' . $value;
                    $found = true;
                }
            }
            if (!$found) {
                $lines[] = $key . '=' . $value;
            }
        }

        return implode($eol, $lines) . $eol;
    }
}
