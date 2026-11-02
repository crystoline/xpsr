<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

/**
 * An object that says how it appears in the log. Eloquent models, Doctrine entities, users, and
 * objects with an id need not implement it; see Keys.
 */
interface AuditableInterface
{
    public function auditKey(): AuditKey;
}
