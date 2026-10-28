<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Adds "request_id" to the extra data of every record logged during a request. */
final class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestId $requestId) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->requestId->get();
        if ($id === null) {
            return $record;
        }

        return $record->with(extra: [...$record->extra, 'request_id' => $id]);
    }
}
