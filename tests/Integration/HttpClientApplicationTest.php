<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Contracts\Routing\RouterInterface;
use Crystoline\Xpsr\HttpClient\HttpClientServiceRegistry;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Tests\TestCase;

/** The HTTP client package in the skeleton application, and TestCase::swap(). */
final class HttpClientApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        // The skeleton does not install the package; register its provider as an application would.
        $application = $this->application();
        $application->getConfig()->set('http_client.clients', ['example' => ['base_uri' => 'https://api.example.test']]);
        (new HttpClientServiceRegistry($application->getContainer()))->register();
    }

    public function testTheProviderRegistersClientsWithTheApplicationFactories(): void
    {
        self::assertInstanceOf(Psr18Client::class, $this->application()->get(ClientInterface::class));
        self::assertInstanceOf(Psr18Client::class, $this->application()->get('http_client.example'));
    }

    public function testSwapReplacesAService(): void
    {
        $factory = new Psr17Factory();
        $fake = new FakeClient($factory->createResponse(204));
        $this->swap(ClientInterface::class, $fake);

        self::assertSame($fake, $this->application()->get(ClientInterface::class));
        self::assertSame(204, $this->application()->get(ClientInterface::class)->sendRequest($factory->createRequest('GET', 'https://api.example.test/'))->getStatusCode());
        self::assertSame('api.example.test', $fake->lastRequest()->getUri()->getHost());
        self::assertInstanceOf(Psr18Client::class, $this->application()->get('http_client.example'), 'Other clients are unchanged.');
    }

    public function testSwapReplacesAServiceOfADeferredProvider(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $this->swap(RouterInterface::class, $router);
        self::assertSame($router, $this->application()->get(RouterInterface::class));
    }
}
