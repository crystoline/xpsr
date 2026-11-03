<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Hmac;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;

/**
 * The JWTs the hub verifies: the application's publisher token, and subscriber tokens that let a
 * browser receive private updates on the selectors they list, in the "mercure" claim as the
 * Mercure specification defines it.
 */
final class Tokens
{
    private ?string $publisher = null;

    public function __construct(private readonly BroadcastOptions $options, private readonly ClockInterface $clock) {}

    /** The token the application publishes with: any topic, private updates too. It does not expire. */
    public function publisher(): string
    {
        return $this->publisher ??= $this->make(['publish' => ['*']], $this->options->publisherSecret(), null);
    }

    /**
     * A token that lets its holder receive private updates on the selectors, for broadcast.ttl.
     *
     * @param list<string> $selectors topics, URI templates such as "users/7/{+rest}", or "*"
     */
    public function subscriber(array $selectors): string
    {
        return $this->make(['subscribe' => $selectors], $this->options->subscriberSecret(), $this->options->ttl);
    }

    /** @param array<string, list<string>> $mercure */
    private function make(array $mercure, string $secret, ?int $ttl): string
    {
        // Whole seconds, so the claims are written as integers.
        $now = new DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
        $builder = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt($now)
            ->withClaim('mercure', $mercure);
        if ($ttl !== null) {
            $builder = $builder->expiresAt($now->modify("+{$ttl} seconds"));
        }

        return $builder->getToken(self::signer($this->options->algorithm), InMemory::plainText($secret))->toString();
    }

    private static function signer(string $algorithm): Signer
    {
        return match ($algorithm) {
            'HS384' => new Hmac\Sha384(),
            'HS512' => new Hmac\Sha512(),
            default => new Hmac\Sha256(),
        };
    }
}
