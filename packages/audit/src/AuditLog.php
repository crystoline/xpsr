<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

use Closure;
use Crystoline\Xpsr\Auth\CurrentIdentity;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * The log. record() writes an entry with the actor, source, IP, and request ID of the current
 * work filled in; for(), by(), recent(), and search() read entries, newest first; query() is a
 * DBAL query builder over the table, for paging with crystoline/xpsr-api. Nothing is changed
 * once written, except by prune().
 */
final class AuditLog
{
    /** @param (Closure(): ?string)|null $requestId the current request's ID, from crystoline/xpsr-log-monolog */
    public function __construct(
        private readonly Connection $db,
        private readonly AuditOptions $options,
        private readonly Keys $keys,
        private readonly AuditContext $context,
        private readonly ClockInterface $clock,
        private readonly ?CurrentIdentity $identity = null,
        private readonly ?Closure $requestId = null,
    ) {}

    /**
     * Records that something happened: "payment.refunded", to a subject, with the values that
     * changed as field => [before, after], and anything else worth keeping as context.
     *
     * @param array<string, array{mixed, mixed}|mixed> $changes
     * @param array<string, mixed> $context
     */
    public function record(string $action, ?object $subject = null, array $changes = [], array $context = []): Entry
    {
        if ($action === '' || strlen($action) > 100 || preg_match('/[\s]/', $action)) {
            throw new InvalidArgumentException("An audit action is a short name such as \"payment.refunded\": \"{$action}\" is not one.");
        }
        $user = $this->identity?->get()?->user;
        $entry = new Entry(
            Uuid::next(),
            $this->clock->now()->setTimezone(new DateTimeZone('UTC')),
            $action,
            $subject === null ? null : $this->keys->of($subject),
            $user === null ? null : $this->keys->of($user),
            $this->context->source,
            $this->requestId === null ? null : ($this->requestId)(),
            $this->context->ip,
            $this->context->userAgent,
            Changes::given($changes, $this->options->redact),
            $context,
        );
        $this->db->insert($this->options->table, [
            'id' => $entry->id,
            'occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s'),
            'action' => $entry->action,
            'subject_type' => $entry->subject?->type,
            'subject_id' => $entry->subject?->id,
            'actor_type' => $entry->actor?->type,
            'actor_id' => $entry->actor?->id,
            'source' => $entry->source,
            'request_id' => $entry->requestId,
            'ip' => $entry->ip,
            'user_agent' => $entry->userAgent,
            'changes' => json_encode($entry->changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'context' => json_encode(Changes::value($entry->context), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        return $entry;
    }

    /**
     * The entries about a subject, newest first.
     *
     * @return list<Entry>
     */
    public function for(object $subject, int $limit = 50): array
    {
        return $this->search(subject: $subject, limit: $limit);
    }

    /**
     * The entries an actor made, newest first.
     *
     * @return list<Entry>
     */
    public function by(object $actor, int $limit = 50): array
    {
        return $this->search(actor: $actor, limit: $limit);
    }

    /** @return list<Entry> */
    public function recent(int $limit = 50): array
    {
        return $this->search(limit: $limit);
    }

    /**
     * Entries matching every condition given, newest first.
     *
     * @return list<Entry>
     */
    public function search(?string $action = null, ?object $subject = null, ?object $actor = null, int $limit = 50): array
    {
        $query = $this->query()->setMaxResults(max(1, $limit));
        if ($action !== null) {
            $query->andWhere('action = :action')->setParameter('action', $action);
        }
        if ($subject !== null) {
            $key = $this->keys->of($subject);
            $query->andWhere('subject_type = :subject_type')->andWhere('subject_id = :subject_id')->setParameter('subject_type', $key->type)->setParameter('subject_id', $key->id);
        }
        if ($actor !== null) {
            $key = $this->keys->of($actor);
            $query->andWhere('actor_type = :actor_type')->andWhere('actor_id = :actor_id')->setParameter('actor_type', $key->type)->setParameter('actor_id', $key->id);
        }

        return array_map(self::entry(...), $query->fetchAllAssociative());
    }

    public function find(string $id): ?Entry
    {
        if (!Uuid::is($id)) {
            return null;
        }
        $row = $this->db->fetchAssociative("SELECT * FROM {$this->options->table} WHERE id = ?", [$id]);

        return $row === false ? null : self::entry($row);
    }

    /** The whole log, newest first, as a query builder to narrow or page; its rows are the table's columns. */
    public function query(): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('*')->from($this->options->table)->orderBy('occurred_at', 'DESC')->addOrderBy('id', 'DESC');
    }

    /** Removes the entries from before a time; returns how many. */
    public function prune(DateTimeImmutable $before): int
    {
        return (int) $this->db->executeStatement(
            "DELETE FROM {$this->options->table} WHERE occurred_at < ?",
            [$before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
    }

    /**
     * An entry from a row of query().
     *
     * @param array<string, mixed> $row
     */
    public static function entry(array $row): Entry
    {
        $changes = json_decode((string) $row['changes'], true);
        $context = json_decode((string) $row['context'], true);

        return new Entry(
            (string) $row['id'],
            new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
            (string) $row['action'],
            isset($row['subject_type'], $row['subject_id']) ? new AuditKey((string) $row['subject_type'], (string) $row['subject_id']) : null,
            isset($row['actor_type'], $row['actor_id']) ? new AuditKey((string) $row['actor_type'], (string) $row['actor_id']) : null,
            (string) $row['source'],
            isset($row['request_id']) ? (string) $row['request_id'] : null,
            isset($row['ip']) ? (string) $row['ip'] : null,
            isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            is_array($changes) ? $changes : [],
            is_array($context) ? $context : [],
        );
    }
}
