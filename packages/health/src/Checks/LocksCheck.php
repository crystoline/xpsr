<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Checks;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;
use Crystoline\Xpsr\Lock\Locks;

/** Takes and releases a lock in the application's lock store, so Redis, the database, or the files answer. */
final class LocksCheck implements CheckInterface
{
    public const KEY = 'xpsr.health.probe';

    public function __construct(private readonly Locks $locks) {}

    public function name(): string
    {
        return 'locks';
    }

    public function check(): Result
    {
        $lock = $this->locks->lock(self::KEY, 5);
        if (!$lock->acquire()) {
            return Result::warning('The probe lock is held: another instance is checking at the same time');
        }
        $lock->release();

        return Result::ok('Acquired and released');
    }
}
