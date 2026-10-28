<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\RateLimit;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides who a request counts against. Each distinct key has its own allowance. The default,
 * IpKeyResolver, counts by client address; an application can count by user or API key by
 * naming its own resolver in a limit's "key" option.
 */
interface ClientKeyResolverInterface
{
    public function key(ServerRequestInterface $request): string;
}
