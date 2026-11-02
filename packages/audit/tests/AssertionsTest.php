<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Audit\Entry;
use Crystoline\Xpsr\Audit\Testing\AuditAssertions;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Project;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Ticket;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/** The AuditAssertions trait, as an application's tests use it. */
final class AssertionsTest extends TestCase
{
    use AuditAssertions;

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

    protected function application(): Application
    {
        return $this->project->application;
    }

    public function testAssertsOnTheLog(): void
    {
        $ticket = new Ticket('T-1');
        $this->assertNotAudited('ticket.closed');
        $this->project->log()->record('ticket.closed', $ticket, ['status' => ['open', 'closed']]);
        $this->project->log()->record('ticket.closed', new Ticket('T-2'));

        $this->assertAudited('ticket.closed');
        $this->assertAudited('ticket.closed', $ticket);
        $this->assertAudited('ticket.closed', null, null, 2);
        $this->assertAudited('ticket.closed', $ticket, static fn(Entry $entry): bool => $entry->after('status') === 'closed');
        $this->assertNotAudited('ticket.opened');
        $this->assertNotAudited('ticket.closed', new Ticket('T-3'));
        $this->assertNotAudited('ticket.closed', $ticket, static fn(Entry $entry): bool => $entry->after('status') === 'open');

        try {
            $this->assertAudited('ticket.opened', $ticket);
            self::fail('Not recorded.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('The audit log has no "ticket.opened" entry about ticket T-1.', $failure->getMessage());
            self::assertStringContainsString('Recent entries: ticket.closed (ticket T-2), ticket.closed (ticket T-1).', $failure->getMessage());
        }
        try {
            $this->assertAudited('ticket.closed', null, null, 1);
            self::fail('Recorded twice.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('The audit log has 2, not 1, "ticket.closed" entries.', $failure->getMessage());
        }
    }
}
