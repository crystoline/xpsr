<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Event;

use Crystoline\Xpsr\Health\Report;

/**
 * Dispatched through the application's PSR-14 dispatcher after the checks ran (not when a
 * cached report was served), with the report. A listener can alert on a failing one.
 */
final class HealthChecked
{
    public function __construct(public readonly Report $report) {}
}
