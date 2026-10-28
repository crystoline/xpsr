<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Keeps sessions in memory for the life of the PHP process: for tests (session.store "array"),
 * never for a web server, where every request would start without its session.
 */
final class ArraySessionStore implements SessionStoreInterface
{
    /** @var array<string, array{string, int}> payload and time of the last write, by ID */
    private array $sessions = [];

    public function __construct(private readonly int $lifetime, private readonly ClockInterface $clock)
    {
        if ($lifetime < 1) {
            throw new InvalidArgumentException('The session lifetime must be at least 1 second.');
        }
    }

    public function read(string $id): ?string
    {
        $entry = $this->sessions[$id] ?? null;

        return $entry === null || $this->expired($entry[1]) ? null : $entry[0];
    }

    public function write(string $id, string $payload): void
    {
        $this->sessions[$id] = [$payload, $this->clock->now()->getTimestamp()];
    }

    public function destroy(string $id): void
    {
        unset($this->sessions[$id]);
    }

    public function prune(): int
    {
        $before = count($this->sessions);
        $this->sessions = array_filter($this->sessions, fn(array $entry): bool => !$this->expired($entry[1]));

        return $before - count($this->sessions);
    }

    private function expired(int $written): bool
    {
        return $written + $this->lifetime <= $this->clock->now()->getTimestamp();
    }
}
