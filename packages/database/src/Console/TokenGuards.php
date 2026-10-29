<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Database\Auth\SanctumTokenRepository;
use Crystoline\Xpsr\Database\Auth\TokenRepository;
use Crystoline\Xpsr\Database\Auth\TokenStoreInterface;
use InvalidArgumentException;

/**
 * The guards whose tokens the auth:token commands manage, those with the token or sanctum
 * driver, and the store each keeps its tokens in.
 *
 * @internal
 */
final class TokenGuards
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly TokenRepository $tokens,
        private readonly SanctumTokenRepository $sanctum,
    ) {}

    /**
     * $guard, or else the first guard with the token or sanctum driver, the default guard first.
     *
     * @throws InvalidArgumentException when there is no such guard
     */
    public function guard(?string $guard): string
    {
        $options = $this->auth->options();
        if ($guard === null) {
            $guards = [...$options->guardsWithDriver('token'), ...$options->guardsWithDriver('sanctum')];
            usort($guards, static fn(string $a, string $b): int => ($b === $options->default) <=> ($a === $options->default));

            return $guards[0] ?? throw new InvalidArgumentException('No guard in auth.guards uses the token or sanctum driver.');
        }
        if (!in_array($options->guards[$guard]['driver'] ?? null, ['token', 'sanctum'], true)) {
            throw new InvalidArgumentException("The guard \"{$guard}\" does not use the token or sanctum driver.");
        }

        return $guard;
    }

    public function store(string $guard): TokenStoreInterface
    {
        return ($this->auth->options()->guards[$guard]['driver'] ?? null) === 'sanctum' ? $this->sanctum : $this->tokens;
    }
}
