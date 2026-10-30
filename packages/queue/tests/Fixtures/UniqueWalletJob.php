<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\Attribute\Unique;
use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\QueueInterface;
use RuntimeException;

/** One per wallet, until it is done. With $again, it queues a copy of itself while it runs. */
#[Unique(for: '1 hour')]
final class UniqueWalletJob implements JobInterface
{
    public function __construct(
        public readonly int $walletId,
        public readonly string $label = '',
        public readonly bool $fail = false,
        public readonly bool $again = false,
    ) {}

    public function uniqueId(): int
    {
        return $this->walletId;
    }

    public function handle(Recorder $recorder, QueueInterface $queue): void
    {
        $recorder->done[] = $this->label;
        if ($this->again) {
            $queue->dispatch(new self($this->walletId, 'again'), queue: 'memory');
        }
        if ($this->fail) {
            throw new RuntimeException("Wallet {$this->walletId} is offline.");
        }
    }
}
