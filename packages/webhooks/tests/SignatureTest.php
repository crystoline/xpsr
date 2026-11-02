<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Tests;

use Crystoline\Xpsr\Webhooks\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    public function testSignsTheTimestampAndBodyAndVerifiesWithinTheTolerance(): void
    {
        $header = Signature::sign('whsec_test', 1_000_000, '{"id":1}');

        self::assertSame('t=1000000,v1=' . hash_hmac('sha256', '1000000.{"id":1}', 'whsec_test'), $header);
        self::assertTrue(Signature::verify('whsec_test', $header, '{"id":1}', 1_000_100, 300));
        self::assertTrue(Signature::verify('whsec_test', $header, '{"id":1}', 999_800, 300), 'A clock a little behind is fine.');
        self::assertFalse(Signature::verify('whsec_test', $header, '{"id":1}', 1_000_301, 300), 'Too old.');
        self::assertFalse(Signature::verify('whsec_test', $header, '{"id":2}', 1_000_100, 300), 'Changed body.');
        self::assertFalse(Signature::verify('other', $header, '{"id":1}', 1_000_100, 300), 'Other secret.');
    }

    public function testAcceptsSeveralDigestsAndRefusesMalformedHeaders(): void
    {
        $digest = Signature::digest('whsec_test', 1_000_000, 'body');

        self::assertTrue(Signature::verify('whsec_test', "t=1000000,v1=0000,v1={$digest}", 'body', 1_000_000, 60), 'Any v1 may match, for a rotated secret.');
        self::assertTrue(Signature::verify('whsec_test', " t=1000000 , v1=" . strtoupper($digest), 'body', 1_000_000, 60), 'Spaces and case do not matter.');
        self::assertFalse(Signature::verify('whsec_test', "v1={$digest}", 'body', 1_000_000, 60), 'No timestamp.');
        self::assertFalse(Signature::verify('whsec_test', 't=1000000', 'body', 1_000_000, 60), 'No digest.');
        self::assertFalse(Signature::verify('whsec_test', "t=soon,v1={$digest}", 'body', 1_000_000, 60));
        self::assertFalse(Signature::verify('whsec_test', '', 'body', 1_000_000, 60));
    }
}
