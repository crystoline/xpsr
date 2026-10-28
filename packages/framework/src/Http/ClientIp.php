<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The address of the client that sent a request.
 *
 * Without trusted proxies this is REMOTE_ADDR. When REMOTE_ADDR is a trusted proxy
 * (app.trusted_proxies), the X-Forwarded-For chain is read from the right, skipping trusted
 * proxies, and the first other address is the client. Addresses a client wrote into the header
 * itself sit further left and are never reached while a trusted proxy appended the real one.
 */
final class ClientIp
{
    /** @param list<string> $trustedProxies IP addresses or CIDR ranges */
    public static function of(ServerRequestInterface $request, array $trustedProxies = []): ?string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if ($trustedProxies === [] || !IpRange::matches($remote, $trustedProxies)) {
            return $remote;
        }
        $chain = array_map(trim(...), explode(',', $request->getHeaderLine('X-Forwarded-For')));
        foreach (array_reverse($chain) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                // A malformed entry: nothing to its left can be trusted.
                break;
            }
            if (!IpRange::matches($address, $trustedProxies)) {
                return $address;
            }
        }

        return $remote;
    }
}
