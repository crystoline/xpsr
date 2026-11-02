<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests;

use Crystoline\Xpsr\Audit\AuditContextMiddleware;
use Crystoline\Xpsr\Audit\AuditKey;
use Crystoline\Xpsr\Audit\AuditOptions;
use Crystoline\Xpsr\Audit\Console\PruneCommand;
use Crystoline\Xpsr\Audit\Entry;
use Crystoline\Xpsr\Audit\Keys;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Entity\Invoice;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Model\Payment;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Project;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Thing;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Ticket;
use Crystoline\Xpsr\Audit\Tests\Fixtures\Widget;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\Identity;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Recording by hand, the context of entries, keys of subjects, reading the log, and pruning. */
final class AuditLogTest extends TestCase
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

    public function testRecordsWhatHappenedWithTheActorAndTheChanges(): void
    {
        $log = $this->project->log();
        $ticket = new Ticket('T-7');

        $anonymous = $log->record('ticket.opened', $ticket);
        self::assertNull($anonymous->actor, 'Nobody is signed in.');
        self::assertSame('console', $anonymous->source);
        self::assertNull($anonymous->ip);
        self::assertSame([], $anonymous->changes);

        $this->project->identity->set(new Identity(new GenericUser(42, null), 'web'));
        $entry = $log->record('ticket.closed', $ticket, ['status' => ['open', 'closed'], 'password' => ['old', 'new'], 'note' => 'done'], ['reason' => 'resolved']);

        self::assertSame('ticket.closed', $entry->action);
        self::assertSame('ticket T-7', (string) $entry->subject);
        self::assertSame(GenericUser::class . ' 42', (string) $entry->actor);
        self::assertSame('2026-11-02T10:00:00+00:00', $entry->occurredAt->format(DATE_ATOM));
        self::assertSame(['status' => ['open', 'closed'], 'password' => ['[redacted]', '[redacted]'], 'note' => [null, 'done']], $entry->changes, 'A redacted field keeps only that it changed; a bare value is the new value.');
        self::assertSame(['reason' => 'resolved'], $entry->context);
        self::assertSame('open', $entry->before('status'));
        self::assertSame('closed', $entry->after('status'));

        $stored = $log->find($entry->id);
        self::assertInstanceOf(Entry::class, $stored);
        self::assertEquals($entry, $stored, 'Read back as written.');
        self::assertNull($log->find('nothing'));
        self::assertSame([$entry->id, $anonymous->id], array_column($log->for($ticket), 'id'), 'Newest first.');
        self::assertSame([$entry->id], array_column($log->by(new GenericUser(42, null)), 'id'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An audit action is a short name');
        $log->record('did a thing', $ticket);
    }

    public function testTheMiddlewareMarksEntriesWithTheRequest(): void
    {
        $log = $this->project->log();
        $middleware = $this->project->application->get(AuditContextMiddleware::class);
        $this->project->requestId->set('req-123');
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://api.test/payments', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Shop/2.0');
        $handler = new class ($log) implements RequestHandlerInterface {
            public ?Entry $entry = null;

            public function __construct(private readonly \Crystoline\Xpsr\Audit\AuditLog $log) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->entry = $this->log->record('payment.created', new Ticket('P-1'));

                return new Response(201);
            }
        };

        self::assertSame(201, $middleware->process($request, $handler)->getStatusCode());
        $entry = $handler->entry;
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('http', $entry->source);
        self::assertSame('203.0.113.9', $entry->ip);
        self::assertSame('Shop/2.0', $entry->userAgent);
        self::assertSame('req-123', $entry->requestId);

        $after = $log->record('ticket.opened', new Ticket('T-2'));
        self::assertSame('console', $after->source, 'The context is cleared when the request is done.');
        self::assertNull($after->ip);
    }

    public function testKeysOfSubjects(): void
    {
        $keys = $this->project->application->get(Keys::class);

        self::assertSame('ticket T-7', (string) $keys->of(new Ticket('T-7')));
        self::assertSame(GenericUser::class . ' 42', (string) $keys->of(new GenericUser(42, null)));
        self::assertSame(Thing::class . ' 5', (string) $keys->of(new Thing(5)));
        self::assertSame(Widget::class . ' w-1', (string) $keys->of(new Widget('w-1')));
        self::assertSame('x 1', (string) $keys->of(new AuditKey('x', '1')));

        $payment = Payment::query()->create(['reference' => 'PAY-1', 'amount' => 100, 'status' => 'pending']);
        self::assertSame(Payment::class . ' ' . $payment->id, (string) $keys->of($payment), 'An Eloquent model: morph class and key.');

        $em = $this->project->em();
        $invoice = new Invoice('INV-1', 500);
        $em->persist($invoice);
        $em->flush();
        self::assertSame(Invoice::class . ' ' . $invoice->id, (string) $keys->of($invoice), 'A Doctrine entity: class and identifier.');

        foreach ([new Thing(), new Widget(), new Payment(), new \stdClass()] as $subject) {
            try {
                $keys->of($subject);
                self::fail(get_debug_type($subject) . ' has no key.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('cannot be audited', $exception->getMessage());
            }
        }
    }

    public function testSearchQueryAndPrune(): void
    {
        $project = new Project(['redact' => ['token']]);
        $project->boot();
        try {
            $log = $project->log();
            $ticket = new Ticket('T-1');
            $first = $log->record('ticket.opened', $ticket);
            $project->clock->modify('+1 hour');
            $second = $log->record('ticket.assigned', $ticket, ['assignee' => [null, 'ada'], 'token' => [null, 't']]);
            $project->clock->modify('+1 hour');
            $third = $log->record('ticket.opened', new Ticket('T-2'));

            self::assertSame([$third->id, $second->id, $first->id], array_column($log->recent(), 'id'));
            self::assertSame([$third->id, $second->id], array_column($log->recent(2), 'id'));
            self::assertSame([$third->id, $first->id], array_column($log->search(action: 'ticket.opened'), 'id'));
            self::assertSame([$first->id], array_column($log->search(action: 'ticket.opened', subject: $ticket), 'id'));
            self::assertSame(['assignee' => [null, 'ada'], 'token' => [null, '[redacted]']], $second->changes, 'audit.redact replaces the defaults.');
            self::assertSame(['password', 'secret'], array_values(array_intersect(['password', 'secret'], AuditOptions::fromArray([])->redact)), 'The defaults still hold password and secret.');

            $query = $log->query();
            self::assertInstanceOf(QueryBuilder::class, $query);
            self::assertCount(3, $query->fetchAllAssociative());
            self::assertSame($third->id, $query->setMaxResults(1)->fetchAssociative()['id'] ?? null, 'Newest first, for paging.');

            self::assertSame(1, $log->prune($second->occurredAt), 'Entries from before the time go.');
            self::assertSame([$third->id, $second->id], array_column($log->recent(), 'id'));

            $prune = new CommandTester($project->application->get(PruneCommand::class));
            self::assertSame(Command::SUCCESS, $prune->execute(['--days' => '1']));
            self::assertStringContainsString('Removed 0 audit log entries older than 1 day(s).', $prune->getDisplay());
            $project->clock->modify('+2 days');
            self::assertSame(Command::SUCCESS, $prune->execute([]));
            self::assertStringContainsString('Removed 0 audit log entries older than 365 day(s).', $prune->getDisplay());
            self::assertSame(Command::SUCCESS, $prune->execute(['--days' => '1']));
            self::assertStringContainsString('Removed 2 audit log entries older than 1 day(s).', $prune->getDisplay());
            self::assertSame(Command::INVALID, $prune->execute(['--days' => 'x']));
        } finally {
            $project->remove();
        }
    }
}
