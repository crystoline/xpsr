<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health;

use Crystoline\Xpsr\Http\ClientIp;
use Crystoline\Xpsr\Http\IpRange;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who may see the details of a report: a request carrying health.secret (the X-Health-Secret
 * header or the "secret" query parameter), or one from a private or loopback address, as a
 * load balancer or an operator on the network is. Everyone else gets the statuses alone.
 */
final class Exposure
{
    public const HEADER = 'X-Health-Secret';

    public const PRIVATE = [
        '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    /** @param list<string> $trustedProxies app.trusted_proxies */
    public function __construct(private readonly ?string $secret, private readonly array $trustedProxies = []) {}

    public function detailsFor(ServerRequestInterface $request): bool
    {
        if ($this->secret !== null) {
            $given = $request->getHeaderLine(self::HEADER);
            if ($given === '') {
                $query = $request->getQueryParams()['secret'] ?? null;
                $given = is_string($query) ? $query : '';
            }
            if ($given !== '' && hash_equals($this->secret, $given)) {
                return true;
            }
        }
        $address = ClientIp::of($request, $this->trustedProxies);

        return $address !== null && IpRange::matches($address, self::PRIVATE);
    }
}
