<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Stamp;

use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The lock a #[Unique] job holds while it is queued or running: the key, with the store's token,
 * that the worker releases it by. Queued jobs are stored with it, so its class name and
 * properties stay the same across versions.
 */
final class UniqueStamp implements StampInterface
{
    public function __construct(
        public readonly Key $key,
        public readonly bool $untilProcessing,
    ) {}
}
