<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Notifications\Console\MakeNotificationsTableCommand;
use Crystoline\Xpsr\Notifications\Delivery;
use Crystoline\Xpsr\Notifications\Event\NotificationSent;
use Crystoline\Xpsr\Notifications\Inbox;
use Crystoline\Xpsr\Notifications\NotifiableKey;
use Crystoline\Xpsr\Notifications\Notifier;
use Crystoline\Xpsr\Notifications\NotifierInterface;
use Crystoline\Xpsr\Notifications\Recipient;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\Payment;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\PaymentReceived;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\User;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The database channel and the Inbox, on the table make:notifications-table writes and on one
 * laid out as Laravel's (a uuid ID and a bigint notifiable_id), with rows as Laravel writes them.
 */
final class InboxTest extends NotificationTestCase
{
    /** @return iterable<string, array{string}> */
    public static function tables(): iterable
    {
        yield 'XPSR\'s migration' => ['xpsr'];
        yield 'Laravel\'s table' => ['laravel'];
    }

    #[DataProvider('tables')]
    public function testTheDatabaseChannelKeepsARowAsLaravelDoes(string $table): void
    {
        $application = $this->application($table);
        $application->get(NotifierInterface::class)->send(new User(42, 'ada@example.com'), new PaymentReceived(new Payment('PAY-1', 5000)));

        $rows = $this->connection()->fetchAllAssociative("SELECT * FROM notifications_{$this->suffix}");
        self::assertCount(1, $rows);
        $row = $rows[0];
        $delivery = $this->delivery('database');
        self::assertSame($delivery->id, strtolower((string) $row['id']));
        self::assertSame([PaymentReceived::class, User::TYPE, '42'], [$row['type'], $row['notifiable_type'], (string) $row['notifiable_id']]);
        self::assertSame(['reference' => 'PAY-1', 'amount' => 5000, 'note' => 'Ẹ ṣé / thanks'], json_decode((string) $row['data'], true));
        self::assertNull($row['read_at']);
        self::assertSame('2026-10-30 13:00:00', substr((string) $row['created_at'], 0, 19), 'In the application\'s time zone (Africa/Lagos), as Laravel stores times.');
        self::assertSame('2026-10-30 13:00:00', substr((string) $row['updated_at'], 0, 19));
    }

    #[DataProvider('tables')]
    public function testTheInboxListsCountsAndMarksOnlyItsOwnersNotifications(string $table): void
    {
        $application = $this->application($table);
        $notifier = $application->get(NotifierInterface::class);
        $inbox = $application->get(Inbox::class);
        $ada = new User(42, 'ada@example.com');
        foreach (['PAY-1', 'PAY-2', 'PAY-3'] as $reference) {
            $notifier->send($ada, new PaymentReceived(new Payment($reference, 100)));
            $this->clock->sleep(60);
        }
        $notifier->send(new User(43, 'grace@example.com'), new PaymentReceived(new Payment('PAY-G', 100)));
        $grace = $inbox->list(new NotifiableKey(User::TYPE, 43))[0]->id;

        $all = $inbox->list($ada);
        self::assertSame(['PAY-3', 'PAY-2', 'PAY-1'], array_map(static fn($n): mixed => $n->data['reference'], $all), 'Newest first.');
        [$third, $second, $first] = array_map(static fn($n): string => $n->id, $all);
        self::assertSame(['PAY-2'], array_map(static fn($n): mixed => $n->data['reference'], $inbox->list($ada, limit: 1, offset: 1)));
        self::assertSame(3, $inbox->unreadCount($ada));

        self::assertSame(2, $inbox->markAsRead($ada, $first, strtoupper($second), $grace, 'not-a-uuid', "'; DROP TABLE x; --"), 'Only Ada\'s, and IDs in upper case are the same IDs.');
        self::assertSame(0, $inbox->markAsRead($ada, $first), 'Already read.');
        self::assertSame(1, $inbox->unreadCount($ada));
        self::assertSame(1, $inbox->unreadCount(new NotifiableKey(User::TYPE, 43)), 'Grace\'s is untouched.');
        self::assertSame(['PAY-3'], array_map(static fn($n): mixed => $n->data['reference'], $inbox->list($ada, unreadOnly: true)));
        $read = $inbox->find($ada, $first);
        self::assertNotNull($read);
        self::assertTrue($read->isRead());
        self::assertSame('2026-10-30 13:03:00', $read->readAt?->format('Y-m-d H:i:s'));
        self::assertSame('Africa/Lagos', $read->readAt->getTimezone()->getName());
        self::assertNull($inbox->find($ada, $grace), 'Another user\'s notification is not found.');
        self::assertNull($inbox->find($ada, 'not-a-uuid'));

        self::assertSame(1, $inbox->markAsUnread($ada, $first));
        self::assertSame(2, $inbox->markAllAsRead($ada));
        self::assertSame(0, $inbox->unreadCount($ada));

        self::assertSame(0, $inbox->delete($ada, $grace));
        self::assertSame(2, $inbox->delete($ada, $first, $third));
        self::assertCount(1, $inbox->list($ada));
        self::assertCount(1, $inbox->list(new NotifiableKey(User::TYPE, 43)));
    }

    public function testAStoredNotificationIsJsonAsLaravelSendsIt(): void
    {
        $application = $this->application('xpsr');
        $application->get(NotifierInterface::class)->send(new User(42), new PaymentReceived(new Payment('PAY-1', 5000)));
        $notification = $application->get(Inbox::class)->list(new User(42))[0];

        self::assertSame(
            [
                'id' => $notification->id,
                'type' => PaymentReceived::class,
                'data' => ['reference' => 'PAY-1', 'amount' => 5000, 'note' => 'Ẹ ṣé / thanks'],
                'read_at' => null,
                'created_at' => '2026-10-30T12:00:00.000000Z',
            ],
            json_decode((string) json_encode($notification), true),
            'Dates in UTC, with microseconds, as Laravel serializes them.',
        );
    }

    #[DataProvider('tables')]
    public function testARowLaravelWroteIsInTheInbox(string $table): void
    {
        $application = $this->application($table);
        $this->connection()->insert("notifications_{$this->suffix}", [
            'id' => '9d1f2a3b-4c5d-4e6f-8a7b-0c1d2e3f4a5b',
            'type' => 'App\Notifications\PaymentReceived',
            'notifiable_type' => User::TYPE,
            'notifiable_id' => 42,
            'data' => '{"reference":"PAY-L","url":"https:\/\/pay.example.com\/payments\/PAY-L"}',
            'read_at' => '2026-10-29 09:00:00',
            'created_at' => '2026-10-29 08:00:00',
            'updated_at' => '2026-10-29 09:00:00',
        ]);

        $notifications = $application->get(Inbox::class)->list(new User(42));
        self::assertCount(1, $notifications);
        self::assertSame('9d1f2a3b-4c5d-4e6f-8a7b-0c1d2e3f4a5b', $notifications[0]->id);
        self::assertSame('App\Notifications\PaymentReceived', $notifications[0]->type);
        self::assertSame(['reference' => 'PAY-L', 'url' => 'https://pay.example.com/payments/PAY-L'], $notifications[0]->data);
        self::assertSame('2026-10-29 08:00:00 Africa/Lagos', $notifications[0]->createdAt?->format('Y-m-d H:i:s e'));
        self::assertTrue($notifications[0]->isRead());
    }

    #[DataProvider('tables')]
    public function testARetriedDeliveryKeepsTheFirstRow(string $table): void
    {
        $application = $this->application($table);
        $application->get(NotifierInterface::class)->send(new User(42), new PaymentReceived(new Payment('PAY-1', 5000)));
        $delivery = $this->delivery('database');

        $application->get(Notifier::class)->deliver($delivery);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM notifications_{$this->suffix}"));
    }

    public function testAnInboxBelongsToARecipientWithAKey(): void
    {
        $application = $this->application('xpsr');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An inbox belongs to a recipient with a notifiable key.');
        $application->get(Inbox::class)->list(Recipient::email('ada@example.com'));
    }

    public function testMakeNotificationsTableWritesOneMigration(): void
    {
        $application = $this->application('xpsr');
        $make = new CommandTester($application->get(MakeNotificationsTableCommand::class));
        self::assertSame(Command::FAILURE, $make->execute([]));
        self::assertStringContainsString("A migration for the notifications table already exists: ", $make->getDisplay());
        self::assertStringContainsString("_create_notifications_{$this->suffix}_table.php", $make->getDisplay());
    }

    private function application(string $table): Application
    {
        $application = $this->boot();
        if ($table === 'xpsr') {
            $this->migrate($application);

            return $application;
        }
        $this->laravelTables($application);

        return $application;
    }

    private function connection(): Connection
    {
        return ($this->application ?? throw new RuntimeException('Not booted.'))->get(Connection::class);
    }

    private function delivery(string $channel): Delivery
    {
        foreach ($this->recorder->events as $event) {
            if ($event instanceof NotificationSent && $event->delivery->channel === $channel) {
                return $event->delivery;
            }
        }
        throw new RuntimeException("Nothing was sent on {$channel}.");
    }
}
