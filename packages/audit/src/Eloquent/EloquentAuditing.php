<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Eloquent;

use Closure;
use Crystoline\Xpsr\Audit\AuditKey;
use Crystoline\Xpsr\Audit\AuditLog;
use Crystoline\Xpsr\Audit\AuditOptions;
use Crystoline\Xpsr\Audit\Changes;
use Crystoline\Xpsr\Audit\Keys;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the Audited trait's model events go. Eloquent's events are static, so AuditServiceRegistry
 * gives this class the log at boot; until then, nothing is recorded.
 *
 * @internal
 */
final class EloquentAuditing
{
    /** @var (Closure(): array{AuditLog, AuditOptions})|null */
    private static ?Closure $services = null;

    /** @param Closure(): array{AuditLog, AuditOptions} $services */
    public static function use(?Closure $services): void
    {
        self::$services = $services;
    }

    public static function record(string $action, Model $model): void
    {
        if (self::$services === null) {
            return;
        }
        [$log, $options] = (self::$services)();
        $key = $model->getKey();
        if ($key === null) {
            return;
        }
        $except = [...$options->except, ...(method_exists($model, 'auditExcept') ? $model->auditExcept() : [])];
        $redact = [...$options->redact, ...(method_exists($model, 'auditRedact') ? $model->auditRedact() : [])];
        [$before, $after] = match ($action) {
            'created' => [[], $model->getAttributes()],
            'deleted' => [$model->getAttributes(), []],
            default => [array_intersect_key($model->getOriginal(), $model->getDirty()), $model->getDirty()],
        };
        $changes = Changes::between($before, $after, $except, $redact);
        if ($changes === [] && $action === 'updated') {
            return;
        }
        $log->record($action, new AuditKey($model->getMorphClass(), Keys::id($key)), $changes);
    }
}
