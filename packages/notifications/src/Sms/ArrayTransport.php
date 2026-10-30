<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Sms;

use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * array://default: keeps sent SMS in memory, for tests.
 *
 *     $sent = $this->application()->get(ArrayTransport::class)->messages();
 *     self::assertSame('+2348012345678', $sent[0]->getPhone());
 */
final class ArrayTransport implements TransportInterface
{
    /** @var list<SmsMessage> */
    private array $messages = [];

    public function send(MessageInterface $message): SentMessage
    {
        if ($message instanceof SmsMessage) {
            $this->messages[] = $message;
        }

        return new SentMessage($message, (string) $this);
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof SmsMessage;
    }

    /** @return list<SmsMessage> the SMS sent so far, oldest first */
    public function messages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function __toString(): string
    {
        return 'array://default';
    }
}
