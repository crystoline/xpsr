<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures;

use Crystoline\Xpsr\Audit\AuditableInterface;
use Crystoline\Xpsr\Audit\AuditKey;

/** Says its own key. */
final class Ticket implements AuditableInterface
{
    public function __construct(public readonly string $reference) {}

    public function auditKey(): AuditKey
    {
        return new AuditKey('ticket', $this->reference);
    }
}
