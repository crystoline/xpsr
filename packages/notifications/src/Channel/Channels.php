<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Channel;

use Closure;
use Crystoline\Xpsr\Notifications\ChannelInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;
use InvalidArgumentException;

/**
 * The channels by name, made on first use: the built-in ones, then notifications.channels.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class Channels
{
    /** @var array<string, ChannelInterface> */
    private array $made = [];

    /** @param array<string, Closure(): ChannelInterface> $factories by name, in order */
    public function __construct(private readonly array $factories) {}

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->factories);
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    public function get(string $name): ChannelInterface
    {
        if (!isset($this->factories[$name])) {
            throw new InvalidArgumentException("There is no notification channel {$name}. Channels: " . implode(', ', $this->names()) . '.');
        }

        return $this->made[$name] ??= ($this->factories[$name])();
    }

    /**
     * The channels $notification goes to when its class does not choose: those that support it.
     *
     * @return list<string>
     */
    public function supporting(NotificationInterface $notification): array
    {
        return array_values(array_filter($this->names(), fn(string $name): bool => $this->get($name)->supports($notification)));
    }
}
