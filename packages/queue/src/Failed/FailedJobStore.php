<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Failed;

use Crystoline\Xpsr\Queue\JobInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Jobs that ran out of retries, in the table queue.failed.table (created by the migration
 * php xpsr make:queue-table writes), for queue:failed, queue:retry, and queue:forget.
 */
final class FailedJobStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly ClockInterface $clock,
    ) {}

    public function add(string $transport, JobInterface $job, Throwable $error): int
    {
        $this->connection->insert($this->table, [
            'transport' => $transport,
            'job' => $job::class,
            'payload' => base64_encode(serialize($job)),
            'error' => mb_substr($error::class . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString(), 0, 8000),
            'failed_at' => $this->clock->now()->getTimestamp(),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return list<FailedJob> oldest first */
    public function all(): array
    {
        return array_map(self::job(...), $this->connection->fetchAllAssociative("SELECT * FROM {$this->table} ORDER BY id"));
    }

    public function find(int $id): ?FailedJob
    {
        $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [$id]);

        return $row === false ? null : self::job($row);
    }

    public function forget(int $id): bool
    {
        return $this->connection->delete($this->table, ['id' => $id]) > 0;
    }

    /** Forget every failed job, and return how many there were. */
    public function flush(): int
    {
        return (int) $this->connection->executeStatement("DELETE FROM {$this->table}");
    }

    /** @param array<string, mixed> $row */
    private static function job(array $row): FailedJob
    {
        $row = array_change_key_case($row, CASE_LOWER);
        $payload = base64_decode((string) $row['payload'], true);
        // A job whose class was renamed or removed cannot be retried, only listed and forgotten.
        $job = $payload === false ? null : @unserialize($payload);

        return new FailedJob(
            (int) $row['id'],
            (string) $row['transport'],
            (string) $row['job'],
            $job instanceof JobInterface ? $job : null,
            (string) $row['error'],
            new DateTimeImmutable('@' . (int) $row['failed_at'])->setTimezone(new DateTimeZone('UTC')),
        );
    }
}
