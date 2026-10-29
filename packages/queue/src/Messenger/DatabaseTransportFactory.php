<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Messenger;

use Crystoline\Xpsr\Database\ConnectionManager;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * database://<connection>?queue_name=<name>: Messenger's Doctrine transport on an XPSR database
 * connection (the default one without a name), in the table queue.table. The table is created by
 * the migration php xpsr make:queue-table writes, never automatically.
 *
 * @internal Used by Transports.
 *
 * @implements TransportFactoryInterface<DoctrineTransport>
 */
final class DatabaseTransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly ConnectionManager $connections, private readonly string $table) {}

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'database://');
    }

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $parts = parse_url($dsn) ?: [];
        parse_str($parts['query'] ?? '', $query);
        $connection = isset($parts['host']) && $parts['host'] !== '' ? $parts['host'] : null;
        $queue = is_string($query['queue_name'] ?? null) && $query['queue_name'] !== '' ? $query['queue_name'] : 'default';

        return new DoctrineTransport(new Connection([
            'table_name' => $this->table,
            'queue_name' => $queue,
            'redeliver_timeout' => (int) ($options['redeliver_timeout'] ?? 3600),
            'auto_setup' => false,
        ], $this->connections->connection($connection)), $serializer);
    }
}
