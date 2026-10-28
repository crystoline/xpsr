<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\HttpClient\Tests;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Foundation\ServiceProviderManager;
use Crystoline\Xpsr\HttpClient\HttpClientOptions;
use Crystoline\Xpsr\HttpClient\HttpClientServiceRegistry;
use Crystoline\Xpsr\HttpClient\Tests\Fixtures\TestServer;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientServiceRegistryTest extends TestCase
{
    private static TestServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testTheDefaultClientSendsRequestsWithConfiguredHeaders(): void
    {
        $container = $this->container(['headers' => ['User-Agent' => 'xpsr-tests']]);
        $client = $container->get(ClientInterface::class);
        self::assertSame($client, $container->get(ClientInterface::class), 'The client is shared.');
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', self::$server->url . '/echo?x=1')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"name":"Ada"}'));

        $response = $client->sendRequest($request);

        self::assertInstanceOf(Response::class, $response, 'Responses come from the application PSR-17 factory.');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Fixture'));
        $echo = $this->decode($response);
        self::assertSame('POST', $echo['method']);
        self::assertSame('/echo?x=1', $echo['uri']);
        self::assertSame('{"name":"Ada"}', $echo['body']);
        self::assertSame('xpsr-tests', $echo['headers']['user-agent']);
        self::assertSame('application/json', $echo['headers']['content-type']);
    }

    public function testErrorStatusesAreResponsesNotExceptions(): void
    {
        $response = $this->container([])->get(ClientInterface::class)
            ->sendRequest((new Psr17Factory())->createRequest('GET', self::$server->url . '/status/404'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testRedirectsAreFollowedUpToTheLimit(): void
    {
        $factory = new Psr17Factory();
        $followed = $this->container([])->get(ClientInterface::class)->sendRequest($factory->createRequest('GET', self::$server->url . '/redirect'));
        self::assertSame(200, $followed->getStatusCode());
        self::assertSame('/echo?redirected=1', $this->decode($followed)['uri']);

        $notFollowed = $this->container(['max_redirects' => 0])->get(ClientInterface::class)->sendRequest($factory->createRequest('GET', self::$server->url . '/redirect'));
        self::assertSame(302, $notFollowed->getStatusCode());
        self::assertSame('/echo?redirected=1', $notFollowed->getHeaderLine('Location'));
    }

    public function testNamedClientsUseTheirBaseUriAndOverrideDefaultHeaders(): void
    {
        $container = $this->container([
            'headers' => ['User-Agent' => 'default-agent', 'X-Default' => 'kept'],
            'clients' => [
                'fixture' => ['base_uri' => self::$server->url . '/api/', 'headers' => ['user-agent' => 'named-agent']],
            ],
        ]);
        $client = $container->get('http_client.fixture');
        self::assertInstanceOf(ClientInterface::class, $client);
        self::assertSame($client, $container->get(HttpClientOptions::serviceId('fixture')));

        $echo = $this->decode($client->sendRequest((new Psr17Factory())->createRequest('GET', 'users/7')));
        self::assertSame('/api/users/7', $echo['uri']);
        self::assertSame('named-agent', $echo['headers']['user-agent']);
        self::assertSame('kept', $echo['headers']['x-default']);

        $default = $this->decode($container->get(ClientInterface::class)->sendRequest((new Psr17Factory())->createRequest('GET', self::$server->url . '/echo')));
        self::assertSame('default-agent', $default['headers']['user-agent'], 'Named clients do not change the default client.');
    }

    public function testTheSymfonyClientIsAvailableWithTheSameOptions(): void
    {
        $client = $this->container(['headers' => ['X-Symfony' => 'yes']])->get(HttpClientInterface::class);
        $echo = $client->request('GET', self::$server->url . '/echo')->toArray();
        self::assertSame('yes', $echo['headers']['x-symfony']);
    }

    public function testConnectionFailuresAreNetworkExceptions(): void
    {
        $client = $this->container(['timeout' => 2])->get(ClientInterface::class);
        $this->expectException(NetworkExceptionInterface::class);
        $client->sendRequest((new Psr17Factory())->createRequest('GET', 'http://127.0.0.1:' . TestServer::freePort() . '/'));
    }

    public function testARequestThatCannotBeSentIsARequestException(): void
    {
        $client = $this->container([])->get(ClientInterface::class);
        try {
            $client->sendRequest((new Psr17Factory())->createRequest('GET', 'users/7'));
            self::fail('A relative URL was sent without a base URI.');
        } catch (RequestExceptionInterface $exception) {
            self::assertSame('users/7', (string) $exception->getRequest()->getUri());
        }
    }

    public function testATimeoutIsANetworkException(): void
    {
        $server = new TestServer();
        try {
            $client = $this->container(['timeout' => 0.5])->get(ClientInterface::class);
            $started = microtime(true);
            try {
                $client->sendRequest((new Psr17Factory())->createRequest('GET', $server->url . '/slow'));
                self::fail('The slow request did not time out.');
            } catch (NetworkExceptionInterface $exception) {
                self::assertLessThan(2.5, microtime(true) - $started);
                self::assertStringContainsString('/slow', (string) $exception->getRequest()->getUri());
            }
        } finally {
            $server->stop();
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'unknown option' => [['timout' => 3], 'Unknown option http_client.timout. Known options: timeout, max_duration, max_redirects, headers.'];
        yield 'base_uri on the default client' => [['base_uri' => 'https://example.test'], 'Unknown option http_client.base_uri'];
        yield 'zero timeout' => [['timeout' => 0], 'http_client.timeout must be a number of seconds greater than 0.'];
        yield 'string timeout' => [['timeout' => '5'], 'http_client.timeout must be a number'];
        yield 'negative duration' => [['max_duration' => -1], 'http_client.max_duration must be a number of seconds (0 for no limit).'];
        yield 'fractional redirects' => [['max_redirects' => 1.5], 'http_client.max_redirects must be a whole number, 0 or more.'];
        yield 'headers not an array' => [['headers' => 'X: y'], 'http_client.headers must map header names to values.'];
        yield 'invalid header name' => [['headers' => ['Bad Name' => 'x']], 'http_client.headers has an invalid header name.'];
        yield 'header injection' => [['headers' => ['X-Test' => "a\r\nInjected: yes"]], 'http_client.headers.X-Test must be a string or a list of strings without line breaks.'];
        yield 'empty header list' => [['headers' => ['X-Test' => []]], 'http_client.headers.X-Test must be a string or a list of strings.'];
        yield 'clients not an array' => [['clients' => 'github'], 'http_client.clients must map client names to option arrays.'];
        yield 'invalid client name' => [['clients' => ['git hub' => []]], 'http_client.clients names may contain only'];
        yield 'client options not an array' => [['clients' => ['github' => 'https://api.github.com']], 'http_client.clients.github must be an array of options.'];
        yield 'relative base_uri' => [['clients' => ['github' => ['base_uri' => '/api']]], 'http_client.clients.github.base_uri must be an absolute http:// or https:// URL.'];
        yield 'other scheme' => [['clients' => ['files' => ['base_uri' => 'ftp://example.test']]], 'http_client.clients.files.base_uri must be an absolute'];
        yield 'named unknown option' => [['clients' => ['github' => ['verify' => false]]], 'Unknown option http_client.clients.github.verify. Known options: timeout, max_duration, max_redirects, headers, base_uri.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationNamesTheSetting(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        HttpClientOptions::named($config);
    }

    public function testInvalidConfigurationFailsWhenTheClientIsFirstUsed(): void
    {
        $container = $this->container(['timeout' => -1]);
        try {
            $container->get(ClientInterface::class);
            self::fail('A client was built from invalid configuration.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString('http_client.timeout must be a number of seconds greater than 0.', $exception->getMessage());
        }
    }

    public function testDefaultsAndHeaderMerging(): void
    {
        self::assertSame(['timeout' => 10.0, 'max_duration' => 0.0, 'max_redirects' => 5, 'headers' => []], HttpClientOptions::defaults([]));
        self::assertSame(
            ['timeout' => 2.0, 'max_duration' => 30.0, 'max_redirects' => 5, 'headers' => ['X-Api' => ['1'], 'accept' => ['text/plain']], 'base_uri' => 'https://api.example.test'],
            HttpClientOptions::named([
                'timeout' => 2,
                'headers' => ['Accept' => 'application/json'],
                'clients' => ['api' => ['base_uri' => 'https://api.example.test', 'max_duration' => 30, 'headers' => ['X-Api' => '1', 'accept' => 'text/plain']]],
            ])['api'] ?? [],
        );
    }

    /** @param array<string, mixed> $settings */
    private function container(array $settings): ServiceContainer
    {
        $container = new ServiceContainer();
        $config = new Configuration();
        $config->set('http_client', $settings);
        $container->instance(Configuration::class, $config);
        $factory = new Psr17Factory();
        $container->instance(ResponseFactoryInterface::class, $factory);
        $container->instance(StreamFactoryInterface::class, $factory);
        (new ServiceProviderManager($container))->boot([HttpClientServiceRegistry::class]);

        return $container;
    }

    /** @return array{method: string, uri: string, headers: array<string, string>, body: string} */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
