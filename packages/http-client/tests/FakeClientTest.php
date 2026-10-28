<?php

declare(strict_types=1);

namespace Crystoline\HttpClient\Tests;

use Crystoline\HttpClient\Testing\FakeClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class FakeClientTest extends TestCase
{
    public function testResponsesAreReturnedInOrderAndRequestsRecorded(): void
    {
        $factory = new Psr17Factory();
        $first = $factory->createResponse(201);
        $client = new FakeClient($first);
        $client->queue(static fn(RequestInterface $request) => $factory->createResponse(200)->withHeader('X-Path', $request->getUri()->getPath()));

        self::assertSame(2, $client->remaining());
        self::assertSame($first, $client->sendRequest($factory->createRequest('POST', 'https://api.example.test/users')));
        self::assertSame('/users/7', $client->sendRequest($factory->createRequest('GET', 'https://api.example.test/users/7'))->getHeaderLine('X-Path'));
        self::assertSame(0, $client->remaining());
        self::assertSame(['POST', 'GET'], array_map(static fn(RequestInterface $request) => $request->getMethod(), $client->requests()));
        self::assertSame('/users/7', $client->lastRequest()->getUri()->getPath());
    }

    public function testAnUnexpectedRequestFailsWithItsMethodAndUri(): void
    {
        $client = new FakeClient();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FakeClient has no response left for DELETE https://api.example.test/users/7.');
        $client->sendRequest((new Psr17Factory())->createRequest('DELETE', 'https://api.example.test/users/7'));
    }

    public function testAClosureCanSimulateANetworkFailure(): void
    {
        $client = new FakeClient(static fn(RequestInterface $request) => throw new class ($request) extends RuntimeException implements NetworkExceptionInterface {
            public function __construct(private readonly RequestInterface $request)
            {
                parent::__construct('Connection refused');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        });
        try {
            $client->sendRequest((new Psr17Factory())->createRequest('GET', 'https://api.example.test/'));
            self::fail('No exception was thrown.');
        } catch (NetworkExceptionInterface $exception) {
            self::assertSame($client->lastRequest(), $exception->getRequest());
        }
    }

    public function testLastRequestNeedsARequest(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FakeClient has not sent any request.');
        (new FakeClient())->lastRequest();
    }
}
