<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests;

use Crystoline\Xpsr\Audit\Entry;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Model\Payment;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Project;
use PHPUnit\Framework\TestCase;

/** The Audited trait on an Eloquent model. */
final class EloquentAuditingTest extends TestCase
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

    public function testCreationUpdatesAndDeletionAreRecorded(): void
    {
        $log = $this->project->log();

        $payment = Payment::query()->create(['reference' => 'PAY-1', 'amount' => 100, 'status' => 'pending', 'card_number' => '4111', 'notes' => 'first', 'password' => 'pw']);
        $created = $log->for($payment);
        self::assertCount(1, $created);
        self::assertSame('created', $created[0]->action);
        self::assertSame(Payment::class . ' ' . $payment->id, (string) $created[0]->subject);
        self::assertSame(
            ['reference' => [null, 'PAY-1'], 'amount' => [null, 100], 'status' => [null, 'pending'], 'card_number' => [null, '[redacted]'], 'password' => [null, '[redacted]'], 'id' => [null, $payment->id]],
            $created[0]->changes,
            'notes is excluded by the model, card_number redacted by the model, password by the defaults.',
        );

        $payment->status = 'paid';
        $payment->notes = 'second';
        $payment->save();
        $updated = $log->search(action: 'updated', subject: $payment);
        self::assertCount(1, $updated);
        self::assertSame(['status' => ['pending', 'paid']], $updated[0]->changes, 'Only what changed, without the excluded field.');

        $payment->notes = 'third';
        $payment->save();
        self::assertCount(1, $log->search(action: 'updated', subject: $payment), 'A change to an excluded field alone records nothing.');
        $payment->save();
        self::assertCount(1, $log->search(action: 'updated', subject: $payment), 'A save without changes records nothing.');

        $payment->delete();
        $deleted = $log->search(action: 'deleted', subject: $payment);
        self::assertCount(1, $deleted);
        self::assertSame('PAY-1', $deleted[0]->before('reference'));
        self::assertNull($deleted[0]->after('reference'));
        self::assertSame('[redacted]', $deleted[0]->before('card_number'));
        self::assertArrayNotHasKey('notes', $deleted[0]->changes);
        self::assertSame(['deleted', 'updated', 'created'], array_map(static fn(Entry $entry): string => $entry->action, $log->for($payment)));
    }
}
