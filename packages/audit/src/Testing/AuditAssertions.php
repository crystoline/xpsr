<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Testing;

use Closure;
use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Audit\AuditLog;
use Crystoline\Xpsr\Audit\Entry;
use PHPUnit\Framework\Assert;

/**
 * Assertions on the log, for an application's tests (ApplicationTestCase has application()):
 *
 *     $this->assertAudited('payment.refunded', $payment);
 *     $this->assertAudited('updated', $payment, fn(Entry $entry): bool => $entry->after('status') === 'refunded');
 *     $this->assertNotAudited('deleted', $payment);
 */
trait AuditAssertions
{
    abstract protected function application(): Application;

    /**
     * @param (Closure(Entry): bool)|null $check a condition an entry must meet to count
     * @param int|null $times how many entries must match; null for one or more
     */
    protected function assertAudited(string $action, ?object $subject = null, ?Closure $check = null, ?int $times = null): void
    {
        $entries = $this->auditEntries($action, $subject, $check);
        $count = count($entries);
        Assert::assertTrue($times === null ? $count > 0 : $count === $times, sprintf(
            'The audit log has %s "%s" entr%s%s%s. Recent entries: %s.',
            $times === null ? 'no' : "{$count}, not {$times},",
            $action,
            $times === null ? 'y' : 'ies',
            $subject === null ? '' : ' about ' . $this->application()->get(\Crystoline\Xpsr\Audit\Keys::class)->of($subject),
            $check === null ? '' : ' matching the check',
            implode(', ', array_map(static fn(Entry $entry): string => $entry->action . ($entry->subject === null ? '' : " ({$entry->subject})"), $this->auditLog()->recent(10))) ?: 'none',
        ));
    }

    /** @param (Closure(Entry): bool)|null $check */
    protected function assertNotAudited(string $action, ?object $subject = null, ?Closure $check = null): void
    {
        Assert::assertCount(0, $this->auditEntries($action, $subject, $check), "The audit log has a \"{$action}\" entry.");
    }

    protected function auditLog(): AuditLog
    {
        return $this->application()->get(AuditLog::class);
    }

    /**
     * @param (Closure(Entry): bool)|null $check
     * @return list<Entry>
     */
    private function auditEntries(string $action, ?object $subject, ?Closure $check): array
    {
        $entries = $this->auditLog()->search(action: $action, subject: $subject, limit: 1000);

        return array_values(array_filter($entries, static fn(Entry $entry): bool => $check === null || $check($entry)));
    }
}
