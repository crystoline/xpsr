<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Eloquent;

/**
 * Records a model's creation, updates, and deletion automatically:
 *
 *     final class Payment extends Model
 *     {
 *         use Audited;
 *
 *         protected function auditExcept(): array { return ['notes']; }
 *         protected function auditRedact(): array { return ['card_number']; }
 *     }
 *
 * Updates record the attributes that changed, as before and after values. auditExcept() names
 * attributes never recorded, auditRedact() attributes recorded as "[redacted]"; both add to
 * audit.except and audit.redact.
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(static fn(self $model) => EloquentAuditing::record('created', $model));
        static::updated(static fn(self $model) => EloquentAuditing::record('updated', $model));
        static::deleted(static fn(self $model) => EloquentAuditing::record('deleted', $model));
    }

    /** @return list<string> */
    public function auditExcept(): array
    {
        return [];
    }

    /** @return list<string> */
    public function auditRedact(): array
    {
        return [];
    }
}
