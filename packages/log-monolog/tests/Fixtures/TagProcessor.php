<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log\Monolog\Tests\Fixtures;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class TagProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, 'tag' => 'fixture']);
    }
}
