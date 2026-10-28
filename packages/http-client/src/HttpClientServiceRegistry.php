<?php

declare(strict_types=1);

namespace Crystoline\HttpClient;

use Crystoline\Configuration;
use Crystoline\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Registers the outgoing HTTP client:
 *
 *   Psr\Http\Client\ClientInterface                   the default PSR-18 client
 *   Symfony\Contracts\HttpClient\HttpClientInterface  the same client with Symfony's API
 *                                                     (streaming, concurrent requests)
 *   http_client.<name>                                a PSR-18 client per entry in
 *                                                     http_client.clients
 *
 * Options come from config/http_client.php; see HttpClientOptions. Named clients share the
 * default client's connections. Responses come from the application's PSR-17 factories.
 */
final class HttpClientServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(
            HttpClientInterface::class,
            static fn(ContainerInterface $container): HttpClientInterface => HttpClient::create(HttpClientOptions::defaults(self::config($container))),
            shared: true,
        );
        $this->container->factory(
            ClientInterface::class,
            static fn(ContainerInterface $container): ClientInterface => self::psr18($container, $container->get(HttpClientInterface::class)),
            shared: true,
        );

        // Named clients are known at registration so that each has its own service ID. Their
        // options are validated again, with the rest, when one is first used.
        $clients = self::config($this->container)['clients'] ?? [];
        foreach (is_array($clients) ? array_keys($clients) : [] as $name) {
            if (!is_string($name)) {
                continue;
            }
            $this->container->factory(HttpClientOptions::serviceId($name), static function (ContainerInterface $container) use ($name): ClientInterface {
                $options = HttpClientOptions::named(self::config($container))[$name];

                return self::psr18($container, $container->get(HttpClientInterface::class)->withOptions($options));
            }, shared: true);
        }
    }

    /** @return array<array-key, mixed> */
    private static function config(ContainerInterface $container): array
    {
        $config = $container->get(Configuration::class)->get('http_client', []);
        if (!is_array($config)) {
            throw new InvalidArgumentException('The http_client configuration must be an array.');
        }

        return $config;
    }

    private static function psr18(ContainerInterface $container, HttpClientInterface $client): ClientInterface
    {
        return new Psr18Client(
            $client,
            $container->has(ResponseFactoryInterface::class) ? $container->get(ResponseFactoryInterface::class) : null,
            $container->has(StreamFactoryInterface::class) ? $container->get(StreamFactoryInterface::class) : null,
        );
    }
}
