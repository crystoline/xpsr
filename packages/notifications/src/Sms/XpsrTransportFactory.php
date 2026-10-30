<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Sms;

use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * The log:// and array:// SMS transports, next to Symfony's provider bridges.
 *
 * @internal Used by NotificationServiceRegistry.
 */
final class XpsrTransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly LogTransport $log, private readonly ArrayTransport $array) {}

    public function create(Dsn $dsn): TransportInterface
    {
        return $dsn->getScheme() === 'log' ? $this->log : $this->array;
    }

    public function supports(Dsn $dsn): bool
    {
        return in_array($dsn->getScheme(), ['log', 'array'], true);
    }
}
