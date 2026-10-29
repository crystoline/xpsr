<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt;

use Crystoline\Xpsr\Auth\BearerToken;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use DateInterval;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Recognizes JWTs from JwtIssuer sent as "Authorization: Bearer <token>" (auth.guards driver
 * "jwt"). A token counts only with a valid signature, the configured issuer and audience, and a
 * current time between nbf and exp (with the configured leeway), and only when it was issued for
 * this guard. Its user is looked up on every request in the guard's provider, so a deleted user's
 * tokens stop working. A token cannot be revoked before it expires;
 * changing the secret ends every token.
 */
final class JwtGuard implements GuardInterface
{
    public function __construct(
        private readonly string $name,
        private readonly JwtOptions $options,
        private readonly UserProviderInterface $users,
        private readonly ClockInterface $clock,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        $text = BearerToken::from($request);
        if ($text === null || substr_count($text, '.') !== 2) {
            return null;
        }
        try {
            $token = (new Parser(new JoseEncoder()))->parse($text);
        } catch (Exception) {
            return null;
        }
        $valid = $token instanceof UnencryptedToken && (new Validator())->validate(
            $token,
            new SignedWith($this->options->signer, $this->options->verificationKey),
            new IssuedBy($this->options->issuer),
            new PermittedFor($this->options->audience),
            new StrictValidAt($this->clock, new DateInterval("PT{$this->options->leeway}S")),
        );
        if (!$valid) {
            return null;
        }
        $claims = $token->claims();
        $subject = $claims->get('sub');
        $scope = $claims->get('scope', '');
        // A token for another guard, such as an admin's on the users' API, does not count here.
        if (!is_string($subject) || $subject === '' || !is_string($scope) || $claims->get('guard') !== $this->name) {
            return null;
        }
        $user = $this->users->findById(ctype_digit($subject) && $subject[0] !== '0' ? (int) $subject : $subject);
        $id = $claims->get('jti');

        return $user === null ? null : new Identity($user, $this->name, array_values(array_filter(explode(' ', $scope))), is_string($id) ? $id : null);
    }

    public function challenge(ServerRequestInterface $request): string
    {
        return BearerToken::challenge($request);
    }
}
