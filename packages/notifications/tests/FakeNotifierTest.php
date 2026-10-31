<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests;

use Crystoline\Xpsr\Notifications\Testing\FakeNotifier;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\Payment;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\PaymentReceived;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\SecurityAlert;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\User;
use PHPUnit\Framework\AssertionFailedError;

/** The fake notifier's assertions; what it keeps is covered in NotifierTest. */
final class FakeNotifierTest extends NotificationTestCase
{
    public function testItAssertsWhatWasSentAndToWhom(): void
    {
        $fake = $this->boot()->get(FakeNotifier::class);
        $ada = new User(42, 'ada@example.com', '+2348012345678');
        $bob = new User(43, 'bob@example.com', '+2348000000000');
        $fake->assertNothingSent();

        $fake->send($ada, new PaymentReceived(new Payment('PAY-7', 9000)));
        $fake->send([$ada, $bob], new PaymentReceived(new Payment('PAY-8', 100)));
        $fake->assertSent(PaymentReceived::class);
        $fake->assertSent(PaymentReceived::class, $ada);
        $fake->assertSent(PaymentReceived::class, new User(42), 2);
        $fake->assertSent(PaymentReceived::class, $bob, 1);
        $fake->assertNotSent(SecurityAlert::class);
        $fake->assertNotSent(PaymentReceived::class, new User(44));

        $sent = ' Sent: ' . PaymentReceived::class . ', ' . PaymentReceived::class . ', ' . PaymentReceived::class . '.';
        foreach ([
            [static fn() => $fake->assertSent(SecurityAlert::class), 'No ' . SecurityAlert::class . ' notification was sent.' . $sent],
            [static fn() => $fake->assertSent(PaymentReceived::class, new User(44)), 'No ' . PaymentReceived::class . ' notification to the recipient was sent.' . $sent],
            [static fn() => $fake->assertSent(PaymentReceived::class, $ada, 1), 'Expected 1 ' . PaymentReceived::class . ' notification(s) to the recipient, not 2.' . $sent],
            [static fn() => $fake->assertSent(PaymentReceived::class, null, 1), 'Expected 1 ' . PaymentReceived::class . ' notification(s), not 3.' . $sent],
            [static fn() => $fake->assertNotSent(PaymentReceived::class), '3 ' . PaymentReceived::class . ' notification(s) were sent.'],
            [static fn() => $fake->assertNotSent(PaymentReceived::class, $bob), '1 ' . PaymentReceived::class . ' notification(s) to the recipient were sent.'],
            [static fn() => $fake->assertNothingSent(), 'Notifications were sent.' . $sent],
        ] as [$assertion, $message]) {
            try {
                $assertion();
                self::fail('The assertion passed: ' . $message);
            } catch (AssertionFailedError $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }
}
