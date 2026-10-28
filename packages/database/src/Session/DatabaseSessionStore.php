<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Session;

use Crystoline\Xpsr\Session\SessionStoreInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Keeps sessions in a database table (session.store "database"), created by the migration that
 * php xpsr make:session-table writes:
 *
 *   id             VARCHAR(128) primary key
 *   payload        TEXT
 *   last_activity  INTEGER, Unix time of the last write (indexed, for pruning)
 */
final class DatabaseSessionStore implements SessionStoreInterface
{
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly int $lifetime,
        private readonly ClockInterface $clock,
    ) {
        if (!preg_match(self::TABLE, $table)) {
            throw new InvalidArgumentException('The session table must be a plain table name: letters, digits, and underscores.');
        }
        if ($lifetime < 1) {
            throw new InvalidArgumentException('The session lifetime must be at least 1 second.');
        }
    }

    public function read(string $id): ?string
    {
        $row = $this->connection->fetchAssociative(
            "SELECT payload FROM {$this->table} WHERE id = ? AND last_activity > ?",
            [$id, $this->now() - $this->lifetime],
        );
        $payload = $row === false ? null : $row['payload'];

        return is_string($payload) ? $payload : null;
    }

    public function write(string $id, string $payload): void
    {
        $values = ['payload' => $payload, 'last_activity' => $this->now()];
        // MySQL counts only changed rows, so an unchanged session in the same second reports 0.
        if ($this->connection->update($this->table, $values, ['id' => $id]) > 0) {
            return;
        }
        try {
            $this->connection->insert($this->table, ['id' => $id, ...$values]);
        } catch (UniqueConstraintViolationException) {
            // Another request created it first, or it was unchanged: write over it.
            $this->connection->update($this->table, $values, ['id' => $id]);
        }
    }

    public function destroy(string $id): void
    {
        $this->connection->delete($this->table, ['id' => $id]);
    }

    public function prune(): int
    {
        return (int) $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE last_activity <= ?",
            [$this->now() - $this->lifetime],
        );
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
