<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests;

use Crystoline\Xpsr\Audit\AuditKey;
use Crystoline\Xpsr\Audit\Tests\Fixtures\CycleEntity\Receipt;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Project;
use PHPUnit\Framework\TestCase;

/** The #[Audited] attribute on a Cycle entity, recorded through the entity behavior the Cycle adapter attaches. */
final class CycleAuditingTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testInsertsUpdatesAndDeletionsAreRecorded(): void
    {
        $em = $this->project->cycle();
        $log = $this->project->log();

        $receipt = new Receipt('R-1', 500);
        $receipt->iban = 'DE89';
        $receipt->notes = 'draft';
        $em->persist($receipt)->run();
        self::assertNotNull($receipt->id);

        $created = $log->for($receipt);
        self::assertCount(1, $created);
        self::assertSame('created', $created[0]->action);
        self::assertSame(Receipt::class . ' ' . $receipt->id, (string) $created[0]->subject);
        $changes = $created[0]->changes;
        ksort($changes);
        self::assertSame(
            ['amount' => [null, 500], 'iban' => [null, '[redacted]'], 'id' => [null, $receipt->id], 'issuedAt' => [null, '2026-11-01T09:00:00+00:00'], 'number' => [null, 'R-1']],
            $changes,
            'notes is excluded, iban redacted, the generated ID known, and dates stored as text.',
        );

        $receipt->amount = 750;
        $receipt->notes = 'final';
        $em->persist($receipt)->run();
        $updated = $log->search(action: 'updated', subject: $receipt);
        self::assertCount(1, $updated);
        self::assertSame(['amount' => [500, 750]], $updated[0]->changes);

        $receipt->notes = 'again';
        $em->persist($receipt)->run();
        self::assertCount(1, $log->search(action: 'updated', subject: $receipt), 'A change to an excluded field alone records nothing.');

        $id = $receipt->id;
        $em->delete($receipt)->run();
        $deleted = $log->search(action: 'deleted', subject: new AuditKey(Receipt::class, (string) $id));
        self::assertCount(1, $deleted);
        self::assertSame(750, $deleted[0]->before('amount'));
        self::assertNull($deleted[0]->after('amount'));
        self::assertSame('[redacted]', $deleted[0]->before('iban'));
        self::assertSame(0, (int) $this->project->db()->fetchOne('SELECT COUNT(*) FROM audit_receipts'));
    }

    public function testEntriesShareTheTransaction(): void
    {
        $em = $this->project->cycle();
        $db = $this->project->db();

        $db->beginTransaction();
        $em->persist(new Receipt('R-2', 10))->run();
        self::assertCount(1, $this->project->log()->recent(), 'Visible inside the transaction.');
        $db->rollBack();

        self::assertCount(0, $this->project->log()->recent(), 'Rolled back with the insert it describes.');
    }
}
