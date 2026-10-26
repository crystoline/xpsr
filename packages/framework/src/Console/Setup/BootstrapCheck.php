<?php

declare(strict_types=1);

namespace Crystoline\Console\Setup;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Boots the application in a fresh PHP process, so it sees newly written files and packages.
 *
 * The child resolves the configured router and matches every route that has no placeholders.
 * Route handlers are not executed.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
class BootstrapCheck
{
    /**
     * @return array{adapter: string, router: string, files: list<string>, routes: int, matched: int}
     * @throws RuntimeException when the application does not boot or a route does not match
     */
    public function run(string $rootDir): array
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/Resources/bootstrap-check.php', $rootDir],
            $rootDir,
            self::environment(),
            timeout: 60,
        );
        $process->run();
        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new RuntimeException($message !== '' ? $message : "The check exited with status {$process->getExitCode()}.");
        }
        try {
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The check printed unexpected output: ' . trim($process->getOutput()));
        }
        if (!is_array($result) || !is_string($result['adapter'] ?? null) || !is_array($result['files'] ?? null)) {
            throw new RuntimeException('The check printed an unexpected result.');
        }

        /** @var array{adapter: string, router: string, files: list<string>, routes: int, matched: int} $result */
        return $result;
    }

    /**
     * Environment for child processes, without values this process loaded from .env.
     *
     * phpdotenv fills $_ENV, and Symfony Process passes $_ENV to children. A child must read the
     * application's .env itself, otherwise stale values would override the file setup just wrote.
     * Variables from the real process environment are kept.
     *
     * @return array<string, false>
     */
    public static function environment(): array
    {
        $real = getenv();
        $remove = [];
        foreach (array_keys($_ENV) as $key) {
            if (is_string($key) && !array_key_exists($key, $real)) {
                $remove[$key] = false;
            }
        }

        return $remove;
    }
}
