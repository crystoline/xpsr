<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Tests;

use Crystoline\Xpsr\Http\Exception\UnauthorizedHttpException;
use Crystoline\Xpsr\Webhooks\Inbound\InboundSignatures;
use Crystoline\Xpsr\Webhooks\Inbound\VerifyWebhook;
use Crystoline\Xpsr\Webhooks\Inbound\VerifyWebhookMiddleware;
use Crystoline\Xpsr\Webhooks\Signature;
use Crystoline\Xpsr\Webhooks\WebhookOptions;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Clock\MockClock;

/** Verifying the webhooks providers send, with each scheme, and the middleware. */
final class InboundTest extends TestCase
{
    private const BODY = '{"type":"charge.succeeded","data":{"id":"ch_1"}}';

    private InboundSignatures $signatures;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-02 10:00:00', 'UTC');
        $this->signatures = new InboundSignatures(WebhookOptions::fromArray(['inbound' => [
            'stripe' => ['scheme' => 'stripe', 'secret' => 'whsec_stripe'],
            'partner' => ['scheme' => 'xpsr', 'secret' => 'whsec_partner', 'tolerance' => '1 minute'],
            'paystack' => ['scheme' => 'hmac', 'header' => 'x-paystack-signature', 'algorithm' => 'sha512', 'secret' => 'sk_test'],
            'github' => ['scheme' => 'hmac', 'header' => 'X-Hub-Signature-256', 'prefix' => 'sha256=', 'secret' => 'gh'],
            'shopify' => ['scheme' => 'hmac', 'header' => 'X-Shopify-Hmac-Sha256', 'encoding' => 'base64', 'secret' => 'shp'],
            'flutterwave' => ['scheme' => 'token', 'header' => 'verif-hash', 'secret' => 'flw-hash'],
        ]]), $this->clock);
    }

    /** @param array<string, string> $headers */
    private function request(array $headers, string $body = self::BODY): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://api.test/webhooks')->withBody($factory->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function testTimestampedSchemes(): void
    {
        $now = $this->clock->now()->getTimestamp();

        self::assertTrue($this->signatures->check('stripe', $this->request(['Stripe-Signature' => Signature::sign('whsec_stripe', $now - 100, self::BODY)])));
        self::assertFalse($this->signatures->check('stripe', $this->request(['Stripe-Signature' => Signature::sign('whsec_stripe', $now - 301, self::BODY)])), 'Older than the 5 minute default.');
        self::assertFalse($this->signatures->check('stripe', $this->request(['Stripe-Signature' => Signature::sign('whsec_other', $now, self::BODY)])));
        self::assertFalse($this->signatures->check('stripe', $this->request([])), 'No header.');

        self::assertTrue($this->signatures->check('partner', $this->request(['X-Webhook-Signature' => Signature::sign('whsec_partner', $now - 59, self::BODY)])), 'The xpsr scheme reads the X-Webhook-Signature header.');
        self::assertFalse($this->signatures->check('partner', $this->request(['X-Webhook-Signature' => Signature::sign('whsec_partner', $now - 61, self::BODY)])), 'Its own tolerance of a minute.');
    }

    public function testHmacAndTokenSchemes(): void
    {
        self::assertTrue($this->signatures->check('paystack', $this->request(['x-paystack-signature' => hash_hmac('sha512', self::BODY, 'sk_test')])));
        self::assertFalse($this->signatures->check('paystack', $this->request(['x-paystack-signature' => hash_hmac('sha512', self::BODY . ' ', 'sk_test')])));
        self::assertTrue($this->signatures->check('github', $this->request(['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', self::BODY, 'gh')])));
        self::assertFalse($this->signatures->check('github', $this->request(['X-Hub-Signature-256' => hash_hmac('sha256', self::BODY, 'gh')])), 'The prefix is required.');
        self::assertTrue($this->signatures->check('shopify', $this->request(['X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', self::BODY, 'shp', true))])));
        self::assertTrue($this->signatures->check('flutterwave', $this->request(['verif-hash' => 'flw-hash'])));
        self::assertFalse($this->signatures->check('flutterwave', $this->request(['verif-hash' => 'flw-hash2'])));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No webhook verifier "paypal" in webhooks.inbound; known: stripe, partner, paystack, github, shopify, flutterwave.');
        $this->signatures->check('paypal', $this->request([]));
    }

    public function testTheMiddlewarePassesVerifiedRequestsOnWithTheirBodyAndRefusesOthers(): void
    {
        $middleware = new VerifyWebhookMiddleware('paystack', $this->signatures);
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(200, [], (string) $request->getBody());
            }
        };

        $response = $middleware->process($this->request(['x-paystack-signature' => hash_hmac('sha512', self::BODY, 'sk_test')]), $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::BODY, (string) $response->getBody(), 'The body is still readable after verification.');
        self::assertSame('paystack', $handler->request?->getAttribute('webhook'));
        self::assertSame('webhooks.inbound.paystack', VerifyWebhook::named('paystack'));

        try {
            $middleware->process($this->request(['x-paystack-signature' => 'nope']), $handler);
            self::fail('A wrong signature is refused.');
        } catch (UnauthorizedHttpException $exception) {
            self::assertSame(401, $exception->getStatusCode());
            self::assertSame('The paystack webhook signature is missing or invalid.', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        VerifyWebhook::named('Pay Stack');
    }
}
