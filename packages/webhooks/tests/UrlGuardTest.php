<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Tests;

use Crystoline\Xpsr\Webhooks\UrlGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Host names resolved by a stub, so no DNS is needed. */
final class UrlGuardTest extends TestCase
{
    private UrlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new UrlGuard(false, static fn(string $host): array => match ($host) {
            'hooks.example.com' => ['203.0.113.5'],
            'internal.corp' => ['10.0.0.5'],
            'dual.example.com' => ['203.0.113.6', 'fd00::1'],
            'v6.example.com' => ['2001:db8::10'],
            default => [],
        });
    }

    public function testPublicHostsPass(): void
    {
        $this->guard->check('https://hooks.example.com/payments');
        $this->guard->check('http://v6.example.com:8443/x?y=1');
        $this->guard->check('https://[2001:db8::10]/hooks');
        $this->expectNotToPerformAssertions();
    }

    public function testHostsResolvingToPrivateAddressesAreRefused(): void
    {
        foreach (['https://internal.corp/hooks' => '10.0.0.5', 'https://dual.example.com/hooks' => 'fd00::1'] as $url => $address) {
            try {
                $this->guard->check($url);
                self::fail("{$url} was accepted.");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString("resolves to {$address}, a private or local address", $exception->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be resolved');
        $this->guard->check('https://nowhere.example.com/hooks');
    }

    public function testAllowingPrivateUrlsSkipsTheAddressCheckOnly(): void
    {
        $guard = new UrlGuard(true, static fn(string $host): array => []);
        $guard->check('http://internal.corp/hooks');
        $guard->check('http://10.0.0.5/hooks');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an http or https URL');
        $guard->check('file:///etc/passwd');
    }
}
