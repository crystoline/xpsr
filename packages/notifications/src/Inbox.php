<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * The notifications the database channel kept, for an in-app inbox:
 *
 *     $inbox->list($user);                     // newest first, 20 at a time
 *     $inbox->list($user, unreadOnly: true, limit: 50, offset: 50);
 *     $inbox->unreadCount($user);
 *     $inbox->markAsRead($user, $id);          // IDs from a request are safe: every call is limited to $user's notifications
 *     $inbox->markAllAsRead($user);
 *     $inbox->delete($user, $id);
 *
 * The table is Laravel's notifications table (php xpsr make:notifications-table for a new one),
 * with times stored as Laravel stores them, "Y-m-d H:i:s" in the application's time zone, so an
 * application that moves from Laravel keeps its users' notifications.
 */
final class Inbox
{
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';
    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly string $table = 'notifications',
    ) {
        if (!preg_match(self::TABLE, $table)) {
            throw new InvalidArgumentException('notifications.database.table must be a plain table name: letters, digits, and underscores.');
        }
    }

    /**
     * $owner's notifications, newest first.
     *
     * @return list<StoredNotification>
     */
    public function list(NotifiableInterface|NotifiableKey $owner, bool $unreadOnly = false, int $limit = 20, int $offset = 0): array
    {
        if ($limit < 1 || $offset < 0) {
            throw new InvalidArgumentException('An inbox list needs a limit of 1 or more and an offset of 0 or more.');
        }
        $query = $this->owned($owner)
            ->select('id', 'type', 'data', 'read_at', 'created_at')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        if ($unreadOnly) {
            $query->andWhere('read_at IS NULL');
        }

        return array_map($this->notification(...), $query->executeQuery()->fetchAllAssociative());
    }

    /** One of $owner's notifications; null for an ID that is not theirs. */
    public function find(NotifiableInterface|NotifiableKey $owner, string $id): ?StoredNotification
    {
        $ids = self::ids([$id]);
        if ($ids === []) {
            return null;
        }
        $row = $this->owned($owner)
            ->select('id', 'type', 'data', 'read_at', 'created_at')
            ->andWhere('id = :notification')
            ->setParameter('notification', $ids[0])
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->notification($row);
    }

    public function unreadCount(NotifiableInterface|NotifiableKey $owner): int
    {
        return (int) $this->owned($owner)->select('COUNT(*)')->andWhere('read_at IS NULL')->executeQuery()->fetchOne();
    }

    /** Marks $owner's unread notifications with these IDs read; returns how many were unread. */
    public function markAsRead(NotifiableInterface|NotifiableKey $owner, string ...$ids): int
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return 0;
        }
        $now = $this->now();

        return (int) $this->owned($owner)
            ->update($this->table)
            ->set('read_at', ':now')
            ->set('updated_at', ':now')
            ->andWhere('read_at IS NULL')
            ->andWhere('id IN (:ids)')
            ->setParameter('now', $now)
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->executeStatement();
    }

    /** Marks all of $owner's notifications read; returns how many were unread. */
    public function markAllAsRead(NotifiableInterface|NotifiableKey $owner): int
    {
        $now = $this->now();

        return (int) $this->owned($owner)
            ->update($this->table)
            ->set('read_at', ':now')
            ->set('updated_at', ':now')
            ->andWhere('read_at IS NULL')
            ->setParameter('now', $now)
            ->executeStatement();
    }

    /** Marks $owner's read notifications with these IDs unread; returns how many were read. */
    public function markAsUnread(NotifiableInterface|NotifiableKey $owner, string ...$ids): int
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return 0;
        }

        return (int) $this->owned($owner)
            ->update($this->table)
            ->set('read_at', 'NULL')
            ->set('updated_at', ':now')
            ->andWhere('read_at IS NOT NULL')
            ->andWhere('id IN (:ids)')
            ->setParameter('now', $this->now())
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->executeStatement();
    }

    /** Deletes $owner's notifications with these IDs; returns how many there were. */
    public function delete(NotifiableInterface|NotifiableKey $owner, string ...$ids): int
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return 0;
        }

        return (int) $this->owned($owner)
            ->delete($this->table)
            ->andWhere('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * Keeps a notification. A second call with the same ID, such as from a retried queue job,
     * keeps the first.
     *
     * @internal Used by the database channel.
     *
     * @param array<array-key, mixed> $data
     */
    public function add(string $id, string $type, NotifiableKey $owner, array $data): void
    {
        $now = $this->now();
        try {
            $this->connection->insert($this->table, [
                'id' => $id,
                'type' => $type,
                'notifiable_type' => $owner->type,
                'notifiable_id' => $owner->id,
                'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Kept already.
        }
    }

    /** A query on $owner's rows, which every query here starts from. */
    private function owned(NotifiableInterface|NotifiableKey $owner): QueryBuilder
    {
        $key = $owner instanceof NotifiableKey ? $owner : $owner->notifiableKey();
        if ($key === null) {
            throw new InvalidArgumentException('An inbox belongs to a recipient with a notifiable key.');
        }

        return $this->connection->createQueryBuilder()
            ->from($this->table)
            ->where('notifiable_type = :owner_type')
            ->andWhere('notifiable_id = :owner_id')
            ->setParameter('owner_type', $key->type)
            ->setParameter('owner_id', (string) $key->id);
    }

    /**
     * The IDs that can be notification IDs, in lower case. Others match no row, and are left out
     * so that a database with a uuid column, such as PostgreSQL, is not given text it rejects.
     *
     * @param array<array-key, string> $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(strtolower(...), $ids), static fn(string $id): bool => preg_match(self::UUID, $id) === 1)));
    }

    /** @param array<string, mixed> $row */
    private function notification(array $row): StoredNotification
    {
        $data = is_string($row['data'] ?? null) ? json_decode($row['data'], true) : null;

        return new StoredNotification(
            strtolower((string) $row['id']),
            (string) $row['type'],
            is_array($data) ? $data : [],
            $this->parse($row['read_at'] ?? null),
            $this->parse($row['created_at'] ?? null),
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($value, 0, 19), $this->timezone());

        return $time === false ? null : $time;
    }

    /** The application's time zone, in which Laravel stores these times. */
    private function timezone(): DateTimeZone
    {
        return $this->clock->now()->getTimezone();
    }
}
