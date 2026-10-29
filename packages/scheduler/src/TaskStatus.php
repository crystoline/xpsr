<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler;

enum TaskStatus: string
{
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
