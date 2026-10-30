<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Log\Monolog\RequestId;
use Crystoline\Xpsr\Queue\JobInterface;
use RuntimeException;

/** Records the request ID it runs with. */
final class RequestIdJob implements JobInterface
{
    public function __construct(public readonly bool $fail = false) {}

    public function handle(Recorder $recorder, RequestId $requestId): void
    {
        $recorder->done[] = $requestId->get() ?? 'none';
        if ($this->fail) {
            throw new RuntimeException('The ledger is closed.');
        }
    }
}
