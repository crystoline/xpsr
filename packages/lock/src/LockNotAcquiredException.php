<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock;

use RuntimeException;
use Throwable;

/** Another process held the lock for longer than Locks::run() was told to wait. */
final class LockNotAcquiredException extends RuntimeException
{
    public function __construct(
        public readonly string $key,
        public readonly float $waited,
        ?Throwable $previous = null,
    ) {
        $after = $waited > 0 ? sprintf(' within %s second(s)', rtrim(rtrim(sprintf('%.3F', $waited), '0'), '.')) : '';
        parent::__construct("Could not acquire the lock \"{$key}\"{$after}: another process holds it.", 0, $previous);
    }
}
