<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests\Fixtures;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\Telemetry\Span;
use Crystoline\Xpsr\Telemetry\Tracer;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** The handlers of the fixture project's routes. */
final class Controllers
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly ConnectionManager $db,
        private readonly LoggerInterface $logger,
        private readonly ClientInterface $client,
        private readonly QueueInterface $queue,
        private readonly Tracer $tracer,
    ) {}

    public function hello(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(['hello' => 'world']);
    }

    public function payment(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $row = $this->db->connection()->fetchAssociative('SELECT * FROM telemetry_payments WHERE id = ?', [$id]);
        $this->logger->info('Payment {id} shown', ['id' => $id]);
        $this->tracer->span('payment.render', static fn(Span $span): Span => $span->setAttribute('payment.id', $id));

        return $row === false ? $this->responses->createResponse(404) : $this->json($row);
    }

    public function boom(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('Boom.');
    }

    public function call(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->client->sendRequest(new Request('GET', 'http://api.example/status?key=1'));

        return $this->responses->createResponse($response->getStatusCode());
    }

    public function queue(ServerRequestInterface $request): ResponseInterface
    {
        $this->queue->dispatch(new RecordJob('receipt'));

        return $this->responses->createResponse(202);
    }

    public function queueFailing(ServerRequestInterface $request): ResponseInterface
    {
        $this->queue->dispatch(new FailingJob());

        return $this->responses->createResponse(202);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): ResponseInterface
    {
        $response = $this->responses->createResponse(200)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write((string) json_encode($data));

        return $response;
    }
}
