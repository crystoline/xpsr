<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt;

use Crystoline\Xpsr\Auth\UserInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;

/**
 * Issues signed JWTs for users, for a sign-in endpoint:
 *
 *     $user = $auth->verify($email, $password) ?? throw new UnauthenticatedException();
 *     $token = $jwt->issue($user, ['tasks:read']);
 *     return $responses->json(['token' => $token->token, 'expires_at' => $token->expiresAt->format(DATE_ATOM)]);
 *
 * Claims: iss, aud, sub (the user's ID), jti (random), iat, nbf, exp, scope (space-separated), and
 * guard: the guard the token is for. Only that guard accepts it, so an admin's token never counts
 * on the users' API guard, even when the two tables reuse IDs.
 */
final class JwtIssuer
{
    private const SCOPE = '/\A[\x21\x23-\x5B\x5D-\x7E]+\z/';

    /** @param list<string> $guards the guards with the jwt driver, the default one first */
    public function __construct(
        private readonly JwtOptions $options,
        private readonly ClockInterface $clock,
        private readonly array $guards = ['api'],
    ) {}

    /**
     * @param list<string> $scopes what the token allows; ["*"] for everything
     * @param string|null $ttl how long it is valid, such as "15 minutes"; auth.jwt.ttl by default
     * @param string|null $guard the jwt guard the token is for; the first one without a name
     */
    public function issue(UserInterface $user, array $scopes = ['*'], ?string $ttl = null, ?string $guard = null): IssuedToken
    {
        $guard ??= $this->guards[0] ?? throw new InvalidArgumentException('No guard in auth.guards uses the jwt driver.');
        if (!in_array($guard, $this->guards, true)) {
            throw new InvalidArgumentException("The guard \"{$guard}\" does not use the jwt driver. JWT guards: " . implode(', ', $this->guards) . '.');
        }
        foreach ($scopes as $scope) {
            if (!preg_match(self::SCOPE, $scope)) {
                throw new InvalidArgumentException('Scopes must be printable text without spaces, quotes, or backslashes, such as tasks:read.');
            }
        }
        $seconds = $ttl === null ? $this->options->ttl : JwtOptions::seconds($ttl);
        if ($seconds < 1) {
            throw new InvalidArgumentException('A token lifetime must be a period such as "15 minutes".');
        }
        // Whole seconds, as the claims are written.
        $now = new DateTimeImmutable('@' . $this->clock->now()->getTimestamp())->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $now->modify("+{$seconds} seconds");
        $id = bin2hex(random_bytes(16));
        $subject = (string) $user->authIdentifier();
        if ($subject === '') {
            throw new InvalidArgumentException('A user ID must not be empty.');
        }

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedBy($this->options->issuer)
            ->permittedFor($this->options->audience)
            ->relatedTo($subject)
            ->identifiedBy($id)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiresAt)
            ->withClaim('scope', implode(' ', $scopes))
            ->withClaim('guard', $guard)
            ->getToken($this->options->signer, $this->options->signingKey);

        return new IssuedToken($token->toString(), $id, $expiresAt, $guard);
    }
}
