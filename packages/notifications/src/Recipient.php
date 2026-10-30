<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use InvalidArgumentException;

/**
 * A recipient without an account, given by address:
 *
 *     $notifier->send(Recipient::phone('+2348012345678'), new SignUpCode($code));
 *     $notifier->send(Recipient::email('ada@example.com')->withPhone('+2348012345678'), $notification);
 *     $notifier->send(new Recipient(['slack' => '#payments']), $notification);
 *
 * It has no notifiable key, so the database and webpush channels skip it.
 */
final class Recipient implements NotifiableInterface
{
    /** @var array<string, string> */
    private readonly array $addresses;

    /** @param array<array-key, mixed> $addresses channel => address, such as ['mail' => 'ada@example.com'] */
    public function __construct(array $addresses)
    {
        $valid = [];
        foreach ($addresses as $channel => $address) {
            if (!is_string($channel) || $channel === '' || !is_string($address) || $address === '') {
                throw new InvalidArgumentException('A recipient\'s addresses must map channel names to addresses, such as [\'mail\' => \'ada@example.com\'].');
            }
            $valid[$channel] = $address;
        }
        $this->addresses = $valid;
    }

    public static function email(string $address): self
    {
        return new self(['mail' => $address]);
    }

    public static function phone(string $number): self
    {
        return new self(['sms' => $number]);
    }

    public function withEmail(string $address): self
    {
        return $this->with('mail', $address);
    }

    public function withPhone(string $number): self
    {
        return $this->with('sms', $number);
    }

    public function with(string $channel, string $address): self
    {
        return new self([...$this->addresses, $channel => $address]);
    }

    public function notificationAddress(string $channel): ?string
    {
        return $this->addresses[$channel] ?? null;
    }

    public function notifiableKey(): ?NotifiableKey
    {
        return null;
    }
}
