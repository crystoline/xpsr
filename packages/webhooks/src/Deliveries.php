<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/** The delivery log, in webhooks.deliveries_table. */
final class Deliveries
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $table,
        private readonly ClockInterface $clock,
    ) {}

    /** @param array<array-key, mixed> $data */
    public function create(Endpoint $endpoint, string $event, array $data): Delivery
    {
        $delivery = new Delivery(Uuid::next(), $endpoint->id, $event, $data, 0, Delivery::PENDING, null, null, $this->now());
        $this->db->insert($this->table, [
            'id' => $delivery->id,
            'endpoint_id' => $endpoint->id,
            'event' => $event,
            'payload' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'attempts' => 0,
            'status' => Delivery::PENDING,
            'last_status' => null,
            'last_error' => null,
            'created_at' => $delivery->createdAt->format('Y-m-d H:i:s'),
            'delivered_at' => null,
            'failed_at' => null,
        ]);

        return $delivery;
    }

    public function find(string $id): ?Delivery
    {
        if (!Uuid::is($id)) {
            return null;
        }
        $row = $this->db->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [$id]);

        return $row === false ? null : self::delivery($row);
    }

    /**
     * The newest deliveries to an endpoint.
     *
     * @return list<Delivery>
     */
    public function forEndpoint(string $endpointId, int $limit = 50): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT * FROM {$this->table} WHERE endpoint_id = ? ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit), [$endpointId]);

        return array_map(self::delivery(...), $rows);
    }

    /** An attempt that did not succeed and will be tried again. */
    public function attempted(string $id, ?int $status, string $error): Delivery
    {
        $this->db->executeStatement(
            "UPDATE {$this->table} SET attempts = attempts + 1, last_status = ?, last_error = ? WHERE id = ?",
            [$status, self::excerpt($error), $id],
        );

        return $this->fetch($id);
    }

    public function delivered(string $id, int $status): Delivery
    {
        $this->db->executeStatement(
            "UPDATE {$this->table} SET attempts = attempts + 1, status = ?, last_status = ?, last_error = NULL, delivered_at = ?, failed_at = NULL WHERE id = ?",
            [Delivery::DELIVERED, $status, $this->now()->format('Y-m-d H:i:s'), $id],
        );

        return $this->fetch($id);
    }

    /** @param bool $countAttempt whether this failure was an attempt of its own, rather than the end of the retries */
    public function failed(string $id, ?int $status, string $error, bool $countAttempt): Delivery
    {
        $this->db->executeStatement(
            "UPDATE {$this->table} SET attempts = attempts + ?, status = ?, last_status = COALESCE(?, last_status), last_error = ?, failed_at = ? WHERE id = ?",
            [$countAttempt ? 1 : 0, Delivery::FAILED, $status, self::excerpt($error), $this->now()->format('Y-m-d H:i:s'), $id],
        );

        return $this->fetch($id);
    }

    /** Back to pending, for another try; the attempts so far are kept. */
    public function reset(string $id): Delivery
    {
        $this->db->update($this->table, ['status' => Delivery::PENDING, 'failed_at' => null], ['id' => $id]);

        return $this->fetch($id);
    }

    /** Removes the delivered and failed deliveries created before a time; returns how many. */
    public function prune(DateTimeImmutable $before): int
    {
        return (int) $this->db->executeStatement(
            "DELETE FROM {$this->table} WHERE status <> ? AND created_at < ?",
            [Delivery::PENDING, $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
    }

    private function fetch(string $id): Delivery
    {
        return $this->find($id) ?? throw new \RuntimeException("The webhook delivery {$id} disappeared.");
    }

    /** @param array<string, mixed> $row */
    private static function delivery(array $row): Delivery
    {
        $payload = json_decode((string) $row['payload'], true);

        return new Delivery(
            (string) $row['id'],
            (string) $row['endpoint_id'],
            (string) $row['event'],
            is_array($payload) ? $payload : [],
            (int) $row['attempts'],
            (string) $row['status'],
            isset($row['last_status']) ? (int) $row['last_status'] : null,
            isset($row['last_error']) ? (string) $row['last_error'] : null,
            Endpoints::date((string) $row['created_at']),
            isset($row['delivered_at']) ? Endpoints::date((string) $row['delivered_at']) : null,
            isset($row['failed_at']) ? Endpoints::date((string) $row['failed_at']) : null,
        );
    }

    private static function excerpt(string $error): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', trim($error)), 0, 500);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
