<?php

declare(strict_types=1);

namespace Crystoline\Factory;

use Crystoline\Configuration;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Crystoline\Http\IpRange;

/**
 * Creates the server request from PHP globals.
 *
 * Forwarding headers (Forwarded, X-Forwarded-*) are kept only when the request comes from a
 * trusted proxy (app.trusted_proxies: IP addresses or CIDR ranges). Otherwise any client could
 * claim, for example, that a plain HTTP request arrived over HTTPS.
 */
class ServerRequestFactory
{
    public function __invoke(ContainerInterface $container): ServerRequestInterface
    {
        $proxies = $container->get(Configuration::class)->get('app.trusted_proxies', []);
        $headers = function_exists('getallheaders') ? getallheaders() : ServerRequestCreator::getHeadersFromServer($_SERVER);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        // PHP fills $_POST only for form bodies; other bodies stay unparsed for the middleware.
        $post = $method === 'POST' && self::isForm($headers) ? $_POST : null;

        return self::create(
            new ServerRequestCreator(
                $container->get(ServerRequestFactoryInterface::class),
                $container->get(UriFactoryInterface::class),
                $container->get(UploadedFileFactoryInterface::class),
                $container->get(StreamFactoryInterface::class),
            ),
            is_array($proxies) ? array_values(array_filter($proxies, 'is_string')) : [],
            $_SERVER,
            $headers,
            $_COOKIE,
            $_GET,
            $post,
            $_FILES,
            fopen('php://input', 'r') ?: null,
        );
    }

    /**
     * @param list<string> $trustedProxies
     * @param array<array-key, mixed> $server
     * @param array<array-key, string> $headers
     * @param array<array-key, mixed> $cookie
     * @param array<array-key, mixed> $get
     * @param array<array-key, mixed>|null $post
     * @param array<array-key, mixed> $files
     * @param resource|string|null $body
     */
    public static function create(
        ServerRequestCreator $creator,
        array $trustedProxies,
        array $server,
        array $headers = [],
        array $cookie = [],
        array $get = [],
        ?array $post = null,
        array $files = [],
        mixed $body = null,
    ): ServerRequestInterface {
        $server['REQUEST_METHOD'] ??= 'GET';
        $remote = $server['REMOTE_ADDR'] ?? null;
        if ($trustedProxies === [] || !is_string($remote) || !IpRange::matches($remote, $trustedProxies)) {
            foreach (array_keys($server) as $key) {
                if (str_starts_with((string) $key, 'HTTP_X_FORWARDED_') || $key === 'HTTP_FORWARDED') {
                    unset($server[$key]);
                }
            }
            foreach (array_keys($headers) as $name) {
                $lower = strtolower((string) $name);
                if (str_starts_with($lower, 'x-forwarded-') || $lower === 'forwarded') {
                    unset($headers[$name]);
                }
            }
        }

        return $creator->fromArrays($server, $headers, $cookie, $get, $post, $files, $body);
    }

    /** @param array<array-key, string> $headers */
    private static function isForm(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'content-type') {
                $type = strtolower(trim(explode(';', $value, 2)[0]));

                return in_array($type, ['application/x-www-form-urlencoded', 'multipart/form-data'], true);
            }
        }

        return false;
    }
}
