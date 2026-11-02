<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\Webhooks\Event\WebhookDelivered;
use Crystoline\Xpsr\Webhooks\Event\WebhookFailed;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends webhooks: send() records a delivery for each subscribed endpoint and queues a
 * DeliverWebhook job; the job calls deliver(), which POSTs the signed JSON and records the
 * outcome. A 2xx answer is success; 410 Gone disables the endpoint; anything else is tried again
 * by the queue, and giveUp() records the failure after the last try.
 */
final class Webhooks implements WebhooksInterface
{
    public function __construct(
        private readonly Endpoints $endpoints,
        private readonly Deliveries $deliveries,
        private readonly UrlGuard $urls,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly QueueInterface $queue,
        private readonly WebhookOptions $options,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    public function send(string $event, array $data): array
    {
        self::checkEvent($event);
        $deliveries = [];
        foreach ($this->endpoints->for($event) as $endpoint) {
            $deliveries[] = $this->queueDelivery($endpoint, $event, $data);
        }

        return $deliveries;
    }

    public function sendTo(Endpoint|string $endpoint, string $event, array $data): Delivery
    {
        self::checkEvent($event);
        $id = $endpoint instanceof Endpoint ? $endpoint->id : $endpoint;
        $endpoint = $this->endpoints->find($id) ?? throw new InvalidArgumentException("No webhook endpoint {$id}.");
        if (!$endpoint->enabled) {
            throw new InvalidArgumentException("The webhook endpoint {$id} is disabled.");
        }

        return $this->queueDelivery($endpoint, $event, $data);
    }

    public function resend(string $deliveryId): Delivery
    {
        $delivery = $this->deliveries->find($deliveryId) ?? throw new InvalidArgumentException("No webhook delivery {$deliveryId}.");
        $delivery = $this->deliveries->reset($delivery->id);
        $this->queue->dispatch(new DeliverWebhook($delivery->id), queue: $this->options->queue);

        return $delivery;
    }

    /**
     * Sends one delivery now: the job's work. Throws DeliveryFailedException when the attempt
     * failed and should be tried again.
     *
     * @internal
     */
    public function deliver(string $deliveryId): void
    {
        $delivery = $this->deliveries->find($deliveryId);
        if ($delivery === null) {
            $this->logger->warning("Webhook delivery {$deliveryId} is gone; nothing sent.");

            return;
        }
        if ($delivery->isDelivered()) {
            return;
        }
        $endpoint = $this->endpoints->find($delivery->endpointId);
        if ($endpoint === null || !$endpoint->enabled) {
            $this->fail($delivery, null, null, 'The endpoint was removed or disabled.', false);

            return;
        }
        try {
            $this->urls->check($endpoint->url);
        } catch (InvalidArgumentException $exception) {
            $this->fail($delivery, $endpoint, null, $exception->getMessage(), false);

            return;
        }

        $timestamp = $this->clock->now()->getTimestamp();
        $body = json_encode([
            'id' => $delivery->id,
            'event' => $delivery->event,
            'created_at' => $delivery->createdAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'data' => $delivery->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $prefix = $this->options->headerPrefix;
        $request = $this->requests->createRequest('POST', $endpoint->url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('User-Agent', $this->options->userAgent)
            ->withHeader("{$prefix}-Id", $delivery->id)
            ->withHeader("{$prefix}-Event", $delivery->event)
            ->withHeader("{$prefix}-Timestamp", (string) $timestamp)
            ->withHeader("{$prefix}-Signature", Signature::sign($endpoint->secret, $timestamp, $body))
            ->withBody($this->streams->createStream($body));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            $delivery = $this->deliveries->attempted($delivery->id, null, $exception->getMessage());
            $this->logger->warning("Webhook {$delivery->event} to {$endpoint->url} failed: {$exception->getMessage()}", ['delivery' => $delivery->id, 'attempt' => $delivery->attempts]);

            throw new DeliveryFailedException($delivery, $exception->getMessage(), $exception);
        }
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            $delivery = $this->deliveries->delivered($delivery->id, $status);
            $this->events?->dispatch(new WebhookDelivered($delivery, $endpoint));

            return;
        }
        $error = "HTTP {$status}" . self::excerpt((string) $response->getBody());
        if ($status === 410) {
            $this->endpoints->disable($endpoint->id);
            $this->fail($delivery, $endpoint, 410, "{$error}: the endpoint answered 410 Gone and was disabled.", true);

            return;
        }
        $delivery = $this->deliveries->attempted($delivery->id, $status, $error);
        $this->logger->warning("Webhook {$delivery->event} to {$endpoint->url} failed: {$error}", ['delivery' => $delivery->id, 'attempt' => $delivery->attempts]);

        throw new DeliveryFailedException($delivery, $error);
    }

    /**
     * Records that the queue gave up on a delivery after its last try: the job's failed() hook.
     *
     * @internal
     */
    public function giveUp(string $deliveryId, Throwable $error): void
    {
        $delivery = $this->deliveries->find($deliveryId);
        if ($delivery === null || $delivery->status !== Delivery::PENDING) {
            return;
        }
        // An attempt's own error was recorded by deliver(); anything else is news.
        $message = $error instanceof DeliveryFailedException ? ($delivery->lastError ?? $error->getMessage()) : $error->getMessage();
        $this->fail($delivery, $this->endpoints->find($delivery->endpointId), null, $message, false);
    }

    private function queueDelivery(Endpoint $endpoint, string $event, array $data): Delivery
    {
        $delivery = $this->deliveries->create($endpoint, $event, $data);
        $this->queue->dispatch(new DeliverWebhook($delivery->id), queue: $this->options->queue);

        return $delivery;
    }

    private function fail(Delivery $delivery, ?Endpoint $endpoint, ?int $status, string $error, bool $countAttempt): void
    {
        $delivery = $this->deliveries->failed($delivery->id, $status, $error, $countAttempt);
        $this->logger->error("Webhook {$delivery->event} to " . ($endpoint->url ?? $delivery->endpointId) . " was not delivered: {$error}", ['delivery' => $delivery->id, 'attempts' => $delivery->attempts]);
        $this->events?->dispatch(new WebhookFailed($delivery, $endpoint, $error));
    }

    private static function checkEvent(string $event): void
    {
        if ($event === '' || str_contains($event, '*') || preg_match('/[\s\r\n]/', $event)) {
            throw new InvalidArgumentException("A webhook event is a name such as \"payment.succeeded\": \"{$event}\" is not one.");
        }
    }

    private static function excerpt(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $body));

        return $text === '' ? '' : ': ' . mb_substr($text, 0, 200);
    }
}
