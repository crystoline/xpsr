<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

/**
 * Users held in memory, for tests and examples:
 *
 *     $users = new InMemoryUserProvider();
 *     $users->add(new GenericUser(7, $hasher->hash('secret'), ['email' => 'ada@example.com']), login: 'ada@example.com');
 */
final class InMemoryUserProvider implements UserProviderInterface
{
    /** @var array<string, UserInterface> */
    private array $byId = [];

    /** @var array<string, string> ID by login */
    private array $logins = [];

    public function add(UserInterface $user, ?string $login = null): void
    {
        $this->byId[(string) $user->authIdentifier()] = $user;
        if ($login !== null) {
            $this->logins[$login] = (string) $user->authIdentifier();
        }
    }

    public function findById(string|int $id): ?UserInterface
    {
        return $this->byId[(string) $id] ?? null;
    }

    public function findByLogin(string $login): ?UserInterface
    {
        $id = $this->logins[$login] ?? null;

        return $id === null ? null : $this->findById($id);
    }

    public function updatePasswordHash(UserInterface $user, string $hash): void
    {
        $id = (string) $user->authIdentifier();
        if (isset($this->byId[$id]) && $this->byId[$id] instanceof GenericUser) {
            $old = $this->byId[$id];
            $this->byId[$id] = new GenericUser($old->authIdentifier(), $hash, $old->toArray());
        }
    }
}
