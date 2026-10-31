<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\JobInterface;
use LogicException;
use RuntimeException;
use Throwable;

/** Fails every time, and its failed() method fails too. */
final class BrokenFailedJob implements JobInterface
{
    public function handle(Recorder $recorder): void
    {
        $recorder->done[] = 'broken attempt';
        throw new RuntimeException('Broken.');
    }

    public function failed(Throwable $error, Recorder $recorder): void
    {
        $recorder->done[] = 'broken failed: ' . $error->getMessage();
        throw new LogicException('failed() broke.');
    }
}
