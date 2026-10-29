<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa;
use Lcobucci\JWT\Signer\Hmac;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa;

/**
 * The "auth.jwt" configuration, validated:
 *
 *   algorithm     HS256 (default), HS384, HS512 with "secret"; RS256 or ES256 with key files
 *   secret        for HS*: at least 32 bytes (48 for HS384, 64 for HS512); "base64:..." is decoded.
 *                 php xpsr auth:jwt-secret makes one.
 *   private_key   for RS256/ES256: the PEM file that signs, relative to the project root
 *   public_key    for RS256/ES256: the PEM file that verifies
 *   ttl           how long a token is valid, such as "1 hour" (default)
 *   issuer        the "iss" claim (default app.url); tokens from other issuers are refused
 *   audience      the "aud" claim (default the issuer)
 *   leeway        seconds of clock difference tolerated (default 30)
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class JwtOptions
{
    private const KNOWN = ['algorithm', 'secret', 'private_key', 'public_key', 'ttl', 'issuer', 'audience', 'leeway'];
    private const HMAC = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64];

    private function __construct(
        public readonly Signer $signer,
        public readonly Key $signingKey,
        public readonly Key $verificationKey,
        public readonly int $ttl,
        public readonly string $issuer,
        public readonly string $audience,
        public readonly int $leeway,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $root, string $appUrl): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option auth.jwt.%s. Known options: %s.', $key, implode(', ', self::KNOWN)));
            }
        }
        $algorithm = $config['algorithm'] ?? 'HS256';
        if (!is_string($algorithm) || !in_array($algorithm, ['HS256', 'HS384', 'HS512', 'RS256', 'ES256'], true)) {
            throw new InvalidArgumentException('auth.jwt.algorithm must be HS256, HS384, HS512, RS256, or ES256.');
        }
        if (isset(self::HMAC[$algorithm])) {
            $secret = self::secret($config['secret'] ?? null, self::HMAC[$algorithm], $algorithm);
            $signer = match ($algorithm) {
                'HS256' => new Hmac\Sha256(),
                'HS384' => new Hmac\Sha384(),
                default => new Hmac\Sha512(),
            };
            $signing = $verification = InMemory::plainText($secret);
        } else {
            $signer = $algorithm === 'RS256' ? new Rsa\Sha256() : new Ecdsa\Sha256();
            $signing = self::keyFile($config['private_key'] ?? null, 'private_key', $root, $algorithm);
            $verification = self::keyFile($config['public_key'] ?? null, 'public_key', $root, $algorithm);
        }
        $ttl = $config['ttl'] ?? '1 hour';
        $seconds = is_string($ttl) ? self::seconds($ttl) : 0;
        if ($seconds < 1) {
            throw new InvalidArgumentException('auth.jwt.ttl must be a period such as "1 hour" or "15 minutes".');
        }
        $issuer = $config['issuer'] ?? ($appUrl !== '' ? $appUrl : 'xpsr');
        $audience = $config['audience'] ?? $issuer;
        if (!is_string($issuer) || $issuer === '' || !is_string($audience) || $audience === '') {
            throw new InvalidArgumentException('auth.jwt.issuer and auth.jwt.audience must be non-empty strings, such as the application URL.');
        }
        $leeway = $config['leeway'] ?? 30;
        if (!is_int($leeway) || $leeway < 0 || $leeway > 300) {
            throw new InvalidArgumentException('auth.jwt.leeway must be 0 to 300 seconds.');
        }

        return new self($signer, $signing, $verification, $seconds, $issuer, $audience, $leeway);
    }

    /** The length of a period such as "1 hour" in seconds; 0 when it is not a period. */
    public static function seconds(string $period): int
    {
        try {
            return (new DateTimeImmutable('@0'))->add(DateInterval::createFromDateString($period))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    /** @return non-empty-string */
    private static function secret(mixed $secret, int $bytes, string $algorithm): string
    {
        if (!is_string($secret) || $secret === '') {
            throw new InvalidArgumentException("auth.jwt.secret is not set. Run php xpsr auth:jwt-secret --write to put one in .env (AUTH_JWT_SECRET).");
        }
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            if ($decoded === false) {
                throw new InvalidArgumentException('auth.jwt.secret starts with "base64:" but the rest is not base64.');
            }
            $secret = $decoded;
        }
        if (strlen($secret) < $bytes) {
            throw new InvalidArgumentException("auth.jwt.secret must be at least {$bytes} bytes for {$algorithm}. Run php xpsr auth:jwt-secret to make one.");
        }

        return $secret;
    }

    private static function keyFile(mixed $path, string $option, string $root, string $algorithm): Key
    {
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException("auth.jwt.{$option} must name a PEM file for {$algorithm}.");
        }
        $file = str_starts_with($path, '/') ? $path : rtrim($root, '/') . '/' . $path;
        if (!is_file($file) || !is_readable($file)) {
            throw new InvalidArgumentException("auth.jwt.{$option}: the key file {$path} does not exist or cannot be read.");
        }
        $contents = file_get_contents($file);
        if ($contents === false || $contents === '') {
            throw new InvalidArgumentException("auth.jwt.{$option}: the key file {$path} is empty.");
        }

        return InMemory::plainText($contents);
    }
}
