<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use Crystoline\Xpsr\Encryption\Encrypter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * The endpoints, in webhooks.endpoints_table. Secrets are stored encrypted with the
 * application's Encrypter (APP_KEY) and come back in clear from find() and all().
 */
final class Endpoints
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $table,
        private readonly Encrypter $encrypter,
        private readonly UrlGuard $urls,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Adds an endpoint with a new secret, which the returned endpoint carries; give it to the
     * subscriber once.
     *
     * @param list<string> $events event names, "payment.*" for a family, or "*" for all
     * @throws InvalidArgumentException for a URL webhooks may not be sent to, or no events
     */
    public function add(string $url, array $events = ['*'], ?string $label = null): Endpoint
    {
        $this->urls->check($url);
        $events = array_values(array_unique(array_map(static fn(string $event): string => trim($event), $events)));
        if ($events === [] || in_array('', $events, true)) {
            throw new InvalidArgumentException('An endpoint needs one or more event names, such as "payment.succeeded", "payment.*", or "*".');
        }
        $endpoint = new Endpoint(Uuid::next(), $url, $events, self::secret(), true, $label, $this->now());
        $this->db->insert($this->table, [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'secret' => $this->encrypter->encryptString($endpoint->secret),
            'label' => $label,
            'enabled' => true,
            'created_at' => $endpoint->createdAt->format('Y-m-d H:i:s'),
            'disabled_at' => null,
        ], ['enabled' => ParameterType::BOOLEAN]);

        return $endpoint;
    }

    public function find(string $id): ?Endpoint
    {
        if (!Uuid::is($id)) {
            return null;
        }
        $row = $this->db->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [$id]);

        return $row === false ? null : $this->endpoint($row);
    }

    /** @return list<Endpoint> */
    public function all(): array
    {
        return array_map($this->endpoint(...), $this->db->fetchAllAssociative("SELECT * FROM {$this->table} ORDER BY created_at, id"));
    }

    /**
     * The enabled endpoints subscribed to an event.
     *
     * @return list<Endpoint>
     */
    public function for(string $event): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT * FROM {$this->table} WHERE enabled = ? ORDER BY created_at, id", [true], [ParameterType::BOOLEAN]);

        return array_values(array_filter(array_map($this->endpoint(...), $rows), static fn(Endpoint $endpoint): bool => $endpoint->subscribes($event)));
    }

    public function disable(string $id): void
    {
        $this->db->update($this->table, ['enabled' => false, 'disabled_at' => $this->now()->format('Y-m-d H:i:s')], ['id' => $id], ['enabled' => ParameterType::BOOLEAN]);
    }

    public function enable(string $id): void
    {
        $this->db->update($this->table, ['enabled' => true, 'disabled_at' => null], ['id' => $id], ['enabled' => ParameterType::BOOLEAN]);
    }

    public function remove(string $id): void
    {
        $this->db->delete($this->table, ['id' => $id]);
    }

    /** Replaces the secret; the returned endpoint carries the new one. */
    public function rotateSecret(string $id): Endpoint
    {
        $endpoint = $this->find($id) ?? throw new InvalidArgumentException("No webhook endpoint {$id}.");
        $secret = self::secret();
        $this->db->update($this->table, ['secret' => $this->encrypter->encryptString($secret)], ['id' => $id]);

        return new Endpoint($endpoint->id, $endpoint->url, $endpoint->events, $secret, $endpoint->enabled, $endpoint->label, $endpoint->createdAt, $endpoint->disabledAt);
    }

    /** @param array<string, mixed> $row */
    private function endpoint(array $row): Endpoint
    {
        $events = json_decode((string) $row['events'], true);

        return new Endpoint(
            (string) $row['id'],
            (string) $row['url'],
            is_array($events) ? array_values(array_map('strval', $events)) : ['*'],
            $this->encrypter->decryptString((string) $row['secret']),
            (bool) $row['enabled'],
            isset($row['label']) ? (string) $row['label'] : null,
            self::date((string) $row['created_at']),
            isset($row['disabled_at']) ? self::date((string) $row['disabled_at']) : null,
        );
    }

    private static function secret(): string
    {
        return 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    /** @internal */
    public static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
