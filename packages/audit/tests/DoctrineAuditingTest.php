<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests;

use Crystoline\Xpsr\Audit\Tests\Fixtures\Entity\Invoice;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Entity\Note;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Project;
use PHPUnit\Framework\TestCase;

/** The #[Audited] attribute on a Doctrine entity, recorded through the entity manager's events. */
final class DoctrineAuditingTest extends TestCase
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
        $em = $this->project->em();
        $log = $this->project->log();
        self::assertTrue($em->getEventManager()->hasListeners('postPersist'), 'The subscriber joined through DoctrineListeners.');

        $invoice = new Invoice('INV-1', 500);
        $invoice->iban = 'DE89';
        $invoice->notes = 'draft';
        $em->persist($invoice);
        $em->persist(new Note('not audited'));
        $em->flush();

        $created = $log->for($invoice);
        self::assertCount(1, $created);
        self::assertSame('created', $created[0]->action);
        self::assertSame(Invoice::class . ' ' . $invoice->id, (string) $created[0]->subject);
        self::assertSame(
            ['id' => [null, $invoice->id], 'iban' => [null, '[redacted]'], 'number' => [null, 'INV-1'], 'amount' => [null, 500], 'issuedAt' => [null, '2026-11-01T09:00:00+00:00']],
            $created[0]->changes,
            'notes is excluded, iban redacted, and dates stored as text.',
        );
        self::assertCount(1, $log->recent(), 'The Note entity is not audited.');

        $invoice->amount = 750;
        $invoice->notes = 'final';
        $em->flush();
        $updated = $log->search(action: 'updated', subject: $invoice);
        self::assertCount(1, $updated);
        self::assertSame(['amount' => [500, 750]], $updated[0]->changes);

        $invoice->notes = 'again';
        $em->flush();
        self::assertCount(1, $log->search(action: 'updated', subject: $invoice), 'A change to an excluded field alone records nothing.');

        $id = $invoice->id;
        $em->remove($invoice);
        $em->flush();
        $deleted = $log->search(action: 'deleted', subject: new \Crystoline\Xpsr\Audit\AuditKey(Invoice::class, (string) $id));
        self::assertCount(1, $deleted);
        self::assertSame(750, $deleted[0]->before('amount'));
        self::assertNull($deleted[0]->after('amount'));
    }

    public function testEntriesShareTheFlushTransaction(): void
    {
        $em = $this->project->em();
        $db = $this->project->db();

        $db->beginTransaction();
        $em->persist(new Invoice('INV-2', 10));
        $em->flush();
        self::assertCount(1, $this->project->log()->recent(), 'Visible inside the transaction.');
        $db->rollBack();

        self::assertCount(0, $this->project->log()->recent(), 'Rolled back with the insert it describes.');
    }
}
