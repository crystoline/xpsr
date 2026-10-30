<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use RuntimeException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Symfony\Component\Notifier\Transport\TransportInterface;

/** failing://default: an SMS provider that is down, from notifications.sms.factories. */
final class FailingSmsTransportFactory implements TransportFactoryInterface
{
    public function create(Dsn $dsn): TransportInterface
    {
        return new class implements TransportInterface {
            public function send(MessageInterface $message): ?SentMessage
            {
                throw new class ('The provider is down.') extends RuntimeException implements TransportExceptionInterface {
                    public function getDebug(): string
                    {
                        return '';
                    }
                };
            }

            public function supports(MessageInterface $message): bool
            {
                return $message instanceof SmsMessage;
            }

            public function __toString(): string
            {
                return 'failing://default';
            }
        };
    }

    public function supports(Dsn $dsn): bool
    {
        return $dsn->getScheme() === 'failing';
    }
}
