<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use Closure;
use Crystoline\Xpsr\Http\IpRange;
use InvalidArgumentException;

/**
 * Checks an endpoint URL before it is stored and before each delivery: http or https, with a
 * host that does not resolve to a loopback, private, link-local, or unspecified address. An
 * endpoint URL comes from outside, and without this check a subscriber could point the
 * application at its own network, such as a cloud metadata service. webhooks.allow_private_urls
 * turns the address check off for development.
 */
final class UrlGuard
{
    private const PRIVATE = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', 'fc00::/7', 'fe80::/10',
    ];

    /** @param (Closure(string): list<string>)|null $resolve the addresses of a host name; null uses DNS */
    public function __construct(private readonly bool $allowPrivate = false, private readonly ?Closure $resolve = null) {}

    /** @throws InvalidArgumentException with why the URL is refused */
    public function check(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? '';
        if ($parts === false || !in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException("The webhook URL must be an http or https URL with a host and no credentials: \"{$url}\" is not one.");
        }
        if ($this->allowPrivate) {
            return;
        }
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve ?? self::lookup(...))($host);
        if ($addresses === []) {
            throw new InvalidArgumentException("The webhook URL's host \"{$host}\" could not be resolved.");
        }
        foreach ($addresses as $address) {
            if (IpRange::matches($address, self::PRIVATE)) {
                throw new InvalidArgumentException("The webhook URL's host \"{$host}\" resolves to {$address}, a private or local address, which webhooks may not be sent to.");
            }
        }
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
