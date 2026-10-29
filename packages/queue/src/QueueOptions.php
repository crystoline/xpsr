<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The "queue" configuration (config/queue.php), validated:
 *
 *   default     the transport for jobs that name none (default "default")
 *   transports  name => ['dsn' => ..., 'options' => [...], 'retry' => [...]]; DSNs:
 *               sync:// (run at once), in-memory://, database://<connection>?queue_name=<name>,
 *               redis://, amqp://, or sqs:// with their Messenger bridges
 *               (default: "default" on QUEUE_DSN, sync:// without it)
 *   retry       max_retries (3), delay ("1 second"), multiplier (2), max_delay ("1 hour");
 *               a transport's own retry options replace these
 *   table       the table of database:// transports (default queue_messages)
 *   failed      the failed jobs table: ['table' => 'failed_jobs', 'connection' => null], or false
 *               to only log jobs that ran out of retries
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class QueueOptions
{
    private const KNOWN = ['default', 'transports', 'retry', 'table', 'failed'];
    private const NAME = '/\A[A-Za-z0-9_.-]+\z/';
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /**
     * @param array<string, array{dsn: string, options: array<array-key, mixed>, retry: array{int, int, float, int}}> $transports
     * @param array{table: string, connection: string|null}|null $failed
     */
    private function __construct(
        public readonly string $default,
        public readonly array $transports,
        public readonly string $table,
        public readonly ?array $failed,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option queue.%s. Known options: %s.', $key, implode(', ', self::KNOWN)));
            }
        }
        $retry = self::retry($config['retry'] ?? [], 'queue.retry', [3, 1000, 2.0, 3_600_000]);
        $transports = $config['transports'] ?? ['default' => ['dsn' => 'sync://']];
        if (!is_array($transports) || $transports === []) {
            throw new InvalidArgumentException('queue.transports must name at least one transport, such as \'default\' => [\'dsn\' => \'sync://\'].');
        }
        $validated = [];
        foreach ($transports as $name => $transport) {
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException('queue.transports names must be letters, digits, dots, underscores, or hyphens.');
            }
            if (!is_array($transport) || !is_string($transport['dsn'] ?? null) || !str_contains($transport['dsn'], '://')) {
                throw new InvalidArgumentException("queue.transports.{$name} must have a \"dsn\", such as sync://, database://default, or redis://localhost:6379/messages.");
            }
            foreach (array_keys($transport) as $key) {
                if (!in_array($key, ['dsn', 'options', 'retry'], true)) {
                    throw new InvalidArgumentException("Unknown option queue.transports.{$name}.{$key}. Known options: dsn, options, retry.");
                }
            }
            $options = $transport['options'] ?? [];
            if (!is_array($options)) {
                throw new InvalidArgumentException("queue.transports.{$name}.options must be an array of Messenger transport options.");
            }
            $validated[$name] = [
                'dsn' => $transport['dsn'],
                'options' => $options,
                'retry' => isset($transport['retry']) ? self::retry($transport['retry'], "queue.transports.{$name}.retry", $retry) : $retry,
            ];
        }
        $default = $config['default'] ?? (isset($validated['default']) ? 'default' : array_key_first($validated));
        if (!is_string($default) || !isset($validated[$default])) {
            throw new InvalidArgumentException('queue.default must name a transport in queue.transports: ' . implode(', ', array_keys($validated)) . '.');
        }
        $table = $config['table'] ?? 'queue_messages';
        if (!is_string($table) || !preg_match(self::TABLE, $table)) {
            throw new InvalidArgumentException('queue.table must be a plain table name: letters, digits, and underscores.');
        }

        return new self($default, $validated, $table, self::failed($config['failed'] ?? []));
    }

    /**
     * @param array{int, int, float, int} $defaults max retries, delay and max delay in milliseconds, multiplier
     * @return array{int, int, float, int}
     */
    private static function retry(mixed $retry, string $prefix, array $defaults): array
    {
        if (!is_array($retry)) {
            throw new InvalidArgumentException("{$prefix} must be an array: max_retries, delay, multiplier, max_delay.");
        }
        foreach (array_keys($retry) as $key) {
            if (!in_array($key, ['max_retries', 'delay', 'multiplier', 'max_delay'], true)) {
                throw new InvalidArgumentException("Unknown option {$prefix}.{$key}. Known options: max_retries, delay, multiplier, max_delay.");
            }
        }
        $max = $retry['max_retries'] ?? $defaults[0];
        $multiplier = $retry['multiplier'] ?? $defaults[2];
        if (!is_int($max) || $max < 0 || !is_numeric($multiplier) || $multiplier < 1) {
            throw new InvalidArgumentException("{$prefix}.max_retries must be 0 or more, and {$prefix}.multiplier 1 or more.");
        }
        $delay = isset($retry['delay']) ? self::milliseconds($retry['delay'], "{$prefix}.delay") : $defaults[1];
        $maxDelay = isset($retry['max_delay']) ? self::milliseconds($retry['max_delay'], "{$prefix}.max_delay") : $defaults[3];

        return [$max, $delay, (float) $multiplier, $maxDelay];
    }

    /** @return array{table: string, connection: string|null}|null */
    private static function failed(mixed $failed): ?array
    {
        if ($failed === false) {
            return null;
        }
        if (!is_array($failed)) {
            throw new InvalidArgumentException('queue.failed must be an array (table, connection), or false to only log failed jobs.');
        }
        $table = $failed['table'] ?? 'failed_jobs';
        $connection = $failed['connection'] ?? null;
        if (!is_string($table) || !preg_match(self::TABLE, $table) || ($connection !== null && !is_string($connection))) {
            throw new InvalidArgumentException('queue.failed.table must be a plain table name, and queue.failed.connection a connection name or null.');
        }

        return ['table' => $table, 'connection' => $connection];
    }

    /** Seconds as an integer, or a period such as "30 seconds", in milliseconds. */
    public static function milliseconds(mixed $period, string $option): int
    {
        if (is_int($period) && $period >= 0) {
            return $period * 1000;
        }
        if (is_string($period)) {
            try {
                $seconds = (new DateTimeImmutable('@0'))->add(DateInterval::createFromDateString($period))->getTimestamp();
            } catch (\Exception) {
                $seconds = -1;
            }
            if ($seconds >= 0) {
                return $seconds * 1000;
            }
        }
        throw new InvalidArgumentException("{$option} must be a number of seconds or a period such as \"30 seconds\".");
    }
}
