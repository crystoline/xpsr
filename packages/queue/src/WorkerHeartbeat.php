<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * The heartbeat queue:work leaves in the application cache for each transport it works on:
 * the time it was last alive, written when it starts, every fifteen seconds while it runs,
 * and when it stops. A health check reads it with lastSeen().
 *
 * @internal Added to the worker by QueueWorkCommand when the application has a cache.
 */
final class WorkerHeartbeat implements EventSubscriberInterface
{
    public const EVERY = 15;
    public const TTL = 86400;

    private int $last = 0;

    /** @param list<string> $transports the transports the worker takes jobs from */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly array $transports,
        private readonly ?ClockInterface $clock = null,
        private readonly int $every = self::EVERY,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::class => 'beat', WorkerRunningEvent::class => 'beat', WorkerStoppedEvent::class => 'beat'];
    }

    public function beat(): void
    {
        $now = ($this->clock?->now() ?? new DateTimeImmutable())->getTimestamp();
        if ($now - $this->last < $this->every) {
            return;
        }
        $this->last = $now;
        foreach ($this->transports as $transport) {
            $item = $this->cache->getItem(self::key($transport));
            $item->set($now);
            $item->expiresAfter(self::TTL);
            $this->cache->save($item);
        }
    }

    /** The Unix time a worker on the transport was last alive, or null when none has reported. */
    public static function lastSeen(CacheItemPoolInterface $cache, string $transport): ?int
    {
        $item = $cache->getItem(self::key($transport));
        $value = $item->isHit() ? $item->get() : null;

        return is_int($value) ? $value : null;
    }

    public static function key(string $transport): string
    {
        return 'xpsr_queue_worker_' . (string) preg_replace('/[^A-Za-z0-9_.]/', '_', $transport);
    }
}
