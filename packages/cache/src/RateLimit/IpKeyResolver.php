<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\RateLimit;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Http\ClientIp;
use Psr\Http\Message\ServerRequestInterface;

/** Counts requests by client address, read through app.trusted_proxies. */
final class IpKeyResolver implements ClientKeyResolverInterface
{
    /** @var list<string> */
    private readonly array $trustedProxies;

    public function __construct(Configuration $config)
    {
        $proxies = $config->get('app.trusted_proxies', []);
        $this->trustedProxies = is_array($proxies) ? array_values(array_filter($proxies, is_string(...))) : [];
    }

    public function key(ServerRequestInterface $request): string
    {
        // Requests without an address (CLI tests, unusual servers) share one allowance.
        return 'ip:' . (ClientIp::of($request, $this->trustedProxies) ?? 'unknown');
    }
}
