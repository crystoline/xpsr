<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests;

use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Mail\Transport\ArrayTransport as MailArrayTransport;
use Crystoline\Xpsr\Notifications\Console\MakeNotificationCommand;
use Crystoline\Xpsr\Notifications\DatabaseNotificationInterface;
use Crystoline\Xpsr\Notifications\Delivery;
use Crystoline\Xpsr\Notifications\Event\NotificationFailed;
use Crystoline\Xpsr\Notifications\Event\NotificationSent;
use Crystoline\Xpsr\Notifications\Inbox;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotifiableKey;
use Crystoline\Xpsr\Notifications\NotificationFailedException;
use Crystoline\Xpsr\Notifications\NotificationOptions;
use Crystoline\Xpsr\Notifications\Notifier;
use Crystoline\Xpsr\Notifications\NotifierInterface;
use Crystoline\Xpsr\Notifications\NotificationServiceRegistry;
use Crystoline\Xpsr\Notifications\Recipient;
use Crystoline\Xpsr\Notifications\SendNotification;
use Crystoline\Xpsr\Notifications\Sms\ArrayTransport as SmsArrayTransport;
use Crystoline\Xpsr\Notifications\SmsNotificationInterface;
use Crystoline\Xpsr\Notifications\Testing\FakeNotifier;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\Deploy;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\Payment;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\PaymentReceived;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\SecurityAlert;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\SlackChannel;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\User;
use Crystoline\Xpsr\Notifications\WebPushMessage;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Notifier\Message\SmsMessage;

/**
 * Sending on each channel, choosing channels, skipping, failures, events, queued notifications,
 * custom channels, the fake, and make:notification.
 */
final class NotifierTest extends NotificationTestCase
{
    private const UUID7 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function testANotificationGoesToEveryChannelItImplements(): void
    {
        $application = $this->boot();
        $this->migrate($application);
        $ada = new User(42, 'ada@example.com', '+2348012345678');

        $application->get(NotifierInterface::class)->send($ada, new PaymentReceived(new Payment('PAY-1', 5000)));

        $emails = $application->get(MailArrayTransport::class)->emails();
        self::assertCount(1, $emails);
        self::assertEquals([new Address('ada@example.com')], $emails[0]->getTo(), 'Without a To, the email goes to the recipient\'s mail address.');
        self::assertEquals([new Address('hello@example.com')], $emails[0]->getFrom(), 'mail.from, as for other email.');
        self::assertSame('Payment received', $emails[0]->getSubject());
        self::assertStringContainsString('You received NGN 5000.', (string) $emails[0]->getHtmlBody(), 'The view is rendered.');

        $sms = $application->get(SmsArrayTransport::class)->messages();
        self::assertCount(1, $sms);
        self::assertSame('+2348012345678', $sms[0]->getPhone());
        self::assertSame('You received NGN 5000. Ref PAY-1', $sms[0]->getSubject());

        $stored = $application->get(Inbox::class)->list($ada);
        self::assertCount(1, $stored);
        self::assertSame(PaymentReceived::class, $stored[0]->type);
        self::assertSame(['reference' => 'PAY-1', 'amount' => 5000, 'note' => 'Ẹ ṣé / thanks'], $stored[0]->data);

        self::assertSame(array_fill(0, 4, NotificationSent::class), $this->events(), 'Web push counts as sent: Ada has no subscribed browser, so there was nothing to push.');
        $deliveries = array_map(static fn(object $event): Delivery => $event instanceof NotificationSent ? $event->delivery : throw new RuntimeException(), $this->recorder->events);
        self::assertSame(['mail', 'sms', 'database', 'webpush'], array_column($deliveries, 'channel'));
        self::assertCount(1, array_unique(array_column($deliveries, 'id')), 'One ID for the recipient, on every channel.');
        self::assertMatchesRegularExpression(self::UUID7, $deliveries[0]->id);
        self::assertSame($deliveries[0]->id, $stored[0]->id, 'The database row has that ID.');
        self::assertSame('+2348012345678', $deliveries[1]->address);
        self::assertTrue((new NotifiableKey(User::TYPE, 42))->equals($deliveries[2]->key ?? throw new RuntimeException()));
    }

    public function testEachRecipientGetsItsOwnIdAndAListCanBeGiven(): void
    {
        $application = $this->boot();
        $this->migrate($application);
        $application->get(NotifierInterface::class)->send([new User(1, 'ada@example.com'), new User(2, 'grace@example.com')], new PaymentReceived(new Payment('PAY-2', 10)));

        self::assertCount(2, $application->get(MailArrayTransport::class)->emails());
        $ids = array_map(static fn(object $event): string => $event instanceof NotificationSent ? $event->delivery->id : '', $this->recorder->events);
        self::assertCount(2, array_unique($ids));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Notifications go to NotifiableInterface recipients, or lists of them; got string.');
        $application->get(NotifierInterface::class)->send(['ada@example.com'], new PaymentReceived(new Payment('PAY-3', 10)));
    }

    public function testAChannelWithNowhereToDeliverIsSkippedAndLogged(): void
    {
        $application = $this->boot();
        $this->migrate($application);
        $notifier = $application->get(NotifierInterface::class);

        $notifier->send(new User(7, 'ada@example.com'), new PaymentReceived(new Payment('PAY-4', 20)));
        self::assertCount(0, $application->get(SmsArrayTransport::class)->messages());
        $skipped = $this->logger->starting('Skipped the');
        self::assertCount(1, $skipped);
        self::assertSame('info', $skipped[0]['level']);
        self::assertSame(['channel' => 'sms', 'notification' => PaymentReceived::class, 'recipient' => 'App\Models\User 7'], $skipped[0]['context'], 'The log names the recipient by key, never by address.');

        // Someone without an account: only the channels they have an address on.
        $this->logger->records = [];
        $notifier->send(Recipient::phone('+2348099999999'), new PaymentReceived(new Payment('PAY-5', 30)));
        self::assertSame('+2348099999999', $application->get(SmsArrayTransport::class)->messages()[0]->getPhone());
        self::assertSame(['mail', 'database', 'webpush'], array_map(static fn(array $record): mixed => $record['context']['channel'], $this->logger->starting('Skipped the')));
        self::assertSame('a recipient without a notifiable key', $this->logger->starting('Skipped the')[0]['context']['recipient']);
    }

    public function testChannelsChoosesAmongTheNotificationsChannels(): void
    {
        $application = $this->boot();
        $notifier = $application->get(NotifierInterface::class);
        $ada = new User(42, 'ada@example.com', '+2348012345678');

        $notifier->send($ada, new SecurityAlert(['sms', 'sms']));
        self::assertCount(0, $application->get(MailArrayTransport::class)->emails());
        $sms = $application->get(SmsArrayTransport::class)->messages();
        self::assertCount(1, $sms, 'A channel named twice is used once.');
        self::assertSame('MPAY', $sms[0]->getFrom(), 'An SmsMessage keeps its sender ID.');

        $notifier->send($ada, new SecurityAlert(['mail']));
        $emails = $application->get(MailArrayTransport::class)->emails();
        self::assertEquals([new Address('security@example.com')], $emails[0]->getTo(), 'An email with a To keeps it.');
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function wrongChannels(): iterable
    {
        yield 'not a list' => [['first' => 'mail'], 'SecurityAlert::channels() must return a list of channel names, such as [\'mail\', \'sms\'].'];
        yield 'unknown' => [['fax'], 'SecurityAlert::channels() returned fax, which is not a channel. Channels: mail, sms, database, webpush.'];
        yield 'not a name' => [[42], 'SecurityAlert::channels() returned int, which is not a channel.'];
        yield 'not implemented' => [['database'], 'SecurityAlert::channels() returned database, but the notification does not go there: implement Crystoline\Xpsr\Notifications\DatabaseNotificationInterface.'];
    }

    #[DataProvider('wrongChannels')]
    public function testChannelsMustNameTheNotificationsChannels(mixed $channels, string $message): void
    {
        $application = $this->boot();
        try {
            $application->get(NotifierInterface::class)->send(new User(1, 'ada@example.com'), new SecurityAlert($channels));
            self::fail('A wrong channels() is an error.');
        } catch (LogicException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertCount(0, $application->get(MailArrayTransport::class)->emails(), 'Nothing is sent.');
    }

    public function testEveryChannelIsTriedAndTheFailuresAreReportedTogether(): void
    {
        $application = $this->boot(['channels' => ['slack' => SlackChannel::class]]);
        $grace = new User(8, 'grace@example.com', slack: 'https://hooks.slack.test/grace');
        $ada = new User(7, 'ada@example.com', slack: 'https://hooks.slack.test/ada');

        try {
            $application->get(NotifierInterface::class)->send([$ada, $grace], new Deploy('The API is offline'));
            self::fail('The Slack deliveries fail.');
        } catch (NotificationFailedException $exception) {
            self::assertSame('Deploy failed on 2 of 4 channels: slack for App\Models\User 7: Slack is offline.; slack for App\Models\User 8: Slack is offline.', $exception->getMessage());
            self::assertSame(Deploy::class, $exception->notification);
            self::assertSame(['slack', 'slack'], array_column($exception->failures(), 'channel'));
            self::assertSame($ada, $exception->failures()[0]['to']);
            self::assertSame('Slack is offline.', $exception->getPrevious()?->getMessage());
        }
        self::assertCount(2, $application->get(MailArrayTransport::class)->emails(), 'The email still went to both.');
        self::assertSame([NotificationSent::class, NotificationFailed::class, NotificationSent::class, NotificationFailed::class], $this->events(), 'Custom channels come after the built-in ones: mail, then slack.');

        // A custom channel works like the built-in ones, and its constructor gets services.
        $application->get(NotifierInterface::class)->send($ada, new Deploy('Deployed v2'));
        self::assertCount(1, $this->recorder->deliveries);
        self::assertSame(['webhook' => 'https://hooks.slack.test/ada', 'text' => 'Deployed v2'], $this->recorder->deliveries[0]->message);
        self::assertSame(Deploy::class, $this->recorder->deliveries[0]->notification);
    }

    public function testAMessageThatCannotBeMadeFailsOnlyItsOwnChannel(): void
    {
        $application = $this->boot();
        $this->migrate($application);
        $broken = new class implements DatabaseNotificationInterface, SmsNotificationInterface {
            public function toDatabase(NotifiableInterface $to): array
            {
                return ['amount' => NAN];
            }

            public function toSms(NotifiableInterface $to): string
            {
                return 'Your balance changed.';
            }
        };

        try {
            $application->get(NotifierInterface::class)->send(new User(9, phone: '+2348011111111'), $broken);
            self::fail('The database message cannot be made.');
        } catch (NotificationFailedException $exception) {
            self::assertStringContainsString('failed on 1 of 2 channels: database for App\Models\User 9: ', $exception->getMessage());
            self::assertStringContainsString('::toDatabase() must return data JSON can encode: Inf and NaN cannot be JSON encoded.', $exception->getMessage());
        }
        self::assertCount(1, $application->get(SmsArrayTransport::class)->messages());
    }

    public function testAListenerThatThrowsIsLoggedAndTheNotificationCountsAsSent(): void
    {
        $listener = static function (object $event): void {
            throw new RuntimeException('The audit log is full.');
        };
        $application = $this->boot(listeners: [$listener]);
        $application->get(NotifierInterface::class)->send(new User(3, phone: '+2348022222222'), new SecurityAlert(['sms']));

        self::assertCount(1, $application->get(SmsArrayTransport::class)->messages());
        $errors = $this->logger->starting('A {event} listener failed');
        self::assertCount(1, $errors);
        self::assertSame('error', $errors[0]['level']);
        self::assertSame(['NotificationSent', SecurityAlert::class, 'sms', 'The audit log is full.'], [$errors[0]['context']['event'], $errors[0]['context']['notification'], $errors[0]['context']['channel'], $errors[0]['context']['error']]);
    }

    public function testQueuedNotificationsAreMadeNowAndSentByTheWorkerOneJobPerChannel(): void
    {
        $application = $this->boot(['queue' => 'memory']);
        $this->migrate($application);
        $ada = new User(42, 'ada@example.com', '+2348012345678');
        $payment = new Payment('PAY-6', 7000);

        $application->get(NotifierInterface::class)->queue($ada, new PaymentReceived($payment));
        $transport = $application->get(Transports::class)->get('memory');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $jobs = array_map(static fn($envelope): object => $envelope->getMessage(), $transport->getSent());
        self::assertCount(4, $jobs, 'One job for each channel.');
        self::assertContainsOnlyInstancesOf(SendNotification::class, $jobs);
        self::assertSame(['mail', 'sms', 'database', 'webpush'], array_map(static fn(SendNotification $job): string => $job->delivery->channel, $jobs));
        self::assertCount(0, $application->get(MailArrayTransport::class)->emails(), 'Nothing is sent before the worker runs.');

        // The messages were made when it was queued: a later change to the entity is not in them.
        $payment->amount = 1;
        $this->work($application, 'memory');

        self::assertStringContainsString('You received NGN 7000.', (string) $application->get(MailArrayTransport::class)->emails()[0]->getHtmlBody());
        self::assertSame('You received NGN 7000. Ref PAY-6', $application->get(SmsArrayTransport::class)->messages()[0]->getSubject());
        self::assertSame(7000, $application->get(Inbox::class)->list($ada)[0]->data['amount']);
        self::assertSame(array_fill(0, 4, NotificationSent::class), $this->events());
    }

    public function testAQueuedChannelThatFailsIsRetriedAlone(): void
    {
        $application = $this->boot(['channels' => ['slack' => SlackChannel::class]]);
        $application->get(NotifierInterface::class)->queue(new User(7, 'ada@example.com', slack: 'https://hooks.slack.test/ada'), new Deploy('Slack offline test'), queue: 'memory');

        $this->work($application, 'memory');

        self::assertCount(1, $application->get(MailArrayTransport::class)->emails(), 'The email was sent once, though the Slack job was retried.');
        self::assertSame([NotificationSent::class, NotificationFailed::class, NotificationFailed::class], $this->events(), 'Each failed attempt is an event: the first try, then the one retry.');
    }

    public function testOnTheSyncTransportAQueuedNotificationIsSentAtOnce(): void
    {
        $application = $this->boot(['channels' => ['slack' => SlackChannel::class]]);
        try {
            $application->get(NotifierInterface::class)->queue(new User(7, 'ada@example.com', slack: 'https://hooks.slack.test/ada'), new Deploy('offline'));
            self::fail('The Slack delivery fails at once.');
        } catch (NotificationFailedException $exception) {
            self::assertSame('Deploy failed on 1 of 2 channels: slack for App\Models\User 7: Slack is offline.', $exception->getMessage());
        }
        self::assertCount(1, $application->get(MailArrayTransport::class)->emails());
    }

    public function testMissingPackagesAreExplained(): void
    {
        $application = $this->boot(registries: [ConsoleServiceRegistry::class, ViewServiceRegistry::class, EventServiceRegistry::class, NotificationServiceRegistry::class]);
        $notifier = $application->get(NotifierInterface::class);
        try {
            $notifier->send(new User(1, 'ada@example.com'), new SecurityAlert(['mail']));
            self::fail('There is no mailer.');
        } catch (NotificationFailedException $exception) {
            self::assertStringContainsString('mail for App\Models\User 1: The mail channel needs crystoline/xpsr-mail: run composer require crystoline/xpsr-mail, and add Crystoline\Xpsr\Mail\MailServiceRegistry to app.registry.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Queued notifications need crystoline/xpsr-queue: run composer require crystoline/xpsr-queue, and add Crystoline\Xpsr\Queue\QueueServiceRegistry to app.registry.');
        $notifier->queue(new User(1, 'ada@example.com'), new SecurityAlert(['mail']));
    }

    public function testTheFakeMakesTheMessagesAndSendsNothing(): void
    {
        $application = $this->boot();
        $fake = $application->get(FakeNotifier::class);
        $ada = new User(42, 'ada@example.com', '+2348012345678');

        $fake->send($ada, new PaymentReceived(new Payment('PAY-7', 9000)));
        $fake->queue(Recipient::phone('+2348033333333'), new SecurityAlert(['sms']), delay: '1 minute', queue: 'memory');

        self::assertCount(0, $application->get(MailArrayTransport::class)->emails());
        self::assertCount(0, $application->get(SmsArrayTransport::class)->messages());
        self::assertCount(2, $fake->sent());
        $sent = $fake->sentTo(new User(42), PaymentReceived::class);
        self::assertCount(1, $sent, 'Another instance of the same user matches.');
        self::assertSame(['mail', 'sms', 'database', 'webpush'], $sent[0]->channels());
        self::assertFalse($sent[0]->queued);
        $sms = $sent[0]->message('sms');
        self::assertInstanceOf(SmsMessage::class, $sms);
        self::assertSame('You received NGN 9000. Ref PAY-7', $sms->getSubject());
        self::assertSame(['type' => PaymentReceived::class, 'data' => ['reference' => 'PAY-7', 'amount' => 9000, 'note' => 'Ẹ ṣé / thanks']], $sent[0]->message('database'));
        self::assertInstanceOf(WebPushMessage::class, $sent[0]->message('webpush'));

        $alert = $fake->sentTo(Recipient::phone('+2348033333333'));
        self::assertCount(1, $alert);
        self::assertTrue($alert[0]->queued);
        self::assertSame(['1 minute', 'memory'], [$alert[0]->delay, $alert[0]->queue]);
        self::assertSame([], $fake->sentTo(new User(43)));
        try {
            $alert[0]->message('mail');
            self::fail('It did not go by email.');
        } catch (LogicException $exception) {
            self::assertSame(SecurityAlert::class . ' did not go to mail; its channels were: sms.', $exception->getMessage());
        }

        $fake->clear();
        self::assertSame([], $fake->sent());
        $this->expectException(LogicException::class);
        $fake->send($ada, new SecurityAlert(['fax']));
    }

    public function testTheNotifierAndCommandsAreRegistered(): void
    {
        $application = $this->boot();
        self::assertSame($application->get(Notifier::class), $application->get(NotifierInterface::class));
        $commands = $application->get(CommandRegistry::class)->all();
        foreach (['make:notification', 'webpush:keys', 'make:notifications-table', 'make:push-subscriptions-table'] as $name) {
            self::assertArrayHasKey($name, $commands);
        }
    }

    public function testMakeNotificationWritesAClassThatWorks(): void
    {
        mkdir($this->root . '/app');
        $application = $this->boot();
        $make = new CommandTester($application->get(MakeNotificationCommand::class));
        self::assertSame(Command::SUCCESS, $make->execute(['name' => 'InvoicePaid']), $make->getDisplay());
        self::assertStringContainsString('$notifier->send($user, new \App\Notification\InvoicePaid(...));', $make->getDisplay());

        $file = $this->root . '/app/Notification/InvoicePaid.php';
        self::assertStringContainsString('final class InvoicePaid implements MailNotificationInterface, DatabaseNotificationInterface', (string) file_get_contents($file));
        require $file;
        $class = 'App\Notification\\' . basename($file, '.php');
        $fake = $application->get(FakeNotifier::class);
        $fake->send(new User(5, 'ada@example.com'), new $class('INV-9'));
        self::assertSame(['mail', 'database'], $fake->sent()[0]->channels(), 'Its channels work: its user has no phone, and the class has no SMS.');
        self::assertSame(['type' => $class, 'data' => ['reference' => 'INV-9']], $fake->sent()[0]->message('database'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function wrongOptions(): iterable
    {
        yield 'unknown option' => [['channel' => []], 'Unknown option notifications.channel. Known options: channels, queue, sms, database, webpush.'];
        yield 'unknown sms option' => [['sms' => ['from' => 'MPAY']], 'Unknown option notifications.sms.from. Known options: dsn, factories.'];
        yield 'channel class' => [['channels' => ['slack' => 'App\Missing']], 'notifications.channels must map channel names'];
        yield 'sms dsn' => [['sms' => ['dsn' => 'termii']], 'notifications.sms.dsn must be a DSN such as termii://API_KEY@default?from=MPAY or log://default.'];
        yield 'table' => [['database' => ['table' => 'notifications; drop']], 'notifications.database.table must be a plain table name'];
        yield 'public key' => [['webpush' => ['public_key' => 'abc']], 'notifications.webpush.public_key must be a VAPID public key'];
        yield 'private key' => [['webpush' => ['private_key' => str_repeat('A', 40)]], 'notifications.webpush.private_key must be a VAPID private key'];
        yield 'subject' => [['webpush' => ['subject' => 'ada@example.com']], 'notifications.webpush.subject must be a mailto: address or an https:// URL'];
        yield 'hosts' => [['webpush' => ['hosts' => ['https://fcm.googleapis.com']]], 'notifications.webpush.hosts must list the hosts of push services'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('wrongOptions')]
    public function testOptionsAreChecked(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        NotificationOptions::fromArray($config);
    }

    public function testRecipientsAndKeysAreChecked(): void
    {
        $recipient = Recipient::email('ada@example.com')->withPhone('+2348012345678')->with('slack', '#payments');
        self::assertSame(['ada@example.com', '+2348012345678', '#payments', null], [
            $recipient->notificationAddress('mail'),
            $recipient->notificationAddress('sms'),
            $recipient->notificationAddress('slack'),
            $recipient->notificationAddress('webpush'),
        ]);
        self::assertNull($recipient->notifiableKey());
        self::assertSame('App\Models\User 42', (string) new NotifiableKey('App\Models\User', 42));
        self::assertTrue((new NotifiableKey('user', 42))->equals(new NotifiableKey('user', '42')));

        foreach ([static fn() => new Recipient(['mail' => '']), static fn() => new NotifiableKey('', 1), static fn() => new NotifiableKey('user', '')] as $make) {
            try {
                $make();
                self::fail('Invalid.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAQueueTransportOtherThanTheDefaultCanBeConfigured(): void
    {
        $application = $this->boot(['queue' => 'memory'], registries: [...self::REGISTRIES]);
        self::assertContains(QueueServiceRegistry::class, $application->getConfig()->get('app.registry'));
        $application->get(NotifierInterface::class)->queue(new User(1, 'ada@example.com'), new SecurityAlert(['mail']), delay: 60);
        $transport = $application->get(Transports::class)->get('memory');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
    }
}
