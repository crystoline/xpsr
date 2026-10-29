<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Auth;

use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Users as Doctrine entities (auth.users driver "doctrine"):
 *
 *   auth.users = ['driver' => 'doctrine', 'entity' => App\Entity\User::class, 'login' => 'email']
 *
 * The entity implements Crystoline\Xpsr\Auth\UserInterface. To let sign-in upgrade outdated
 * password hashes, it also implements PasswordUpgradableInterface.
 */
final class DoctrineUserProvider implements UserProviderInterface
{
    /** @param class-string<UserInterface> $entity */
    public function __construct(
        private readonly EntityManagerInterface $entities,
        private readonly string $entity,
        private readonly string $loginField = 'email',
    ) {}

    public function findById(string|int $id): ?UserInterface
    {
        return $this->entities->find($this->entity, $id);
    }

    public function findByLogin(string $login): ?UserInterface
    {
        if ($login === '') {
            return null;
        }
        $user = $this->entities->getRepository($this->entity)->findOneBy([$this->loginField => $login]);

        return $user instanceof UserInterface ? $user : null;
    }

    public function updatePasswordHash(UserInterface $user, string $hash): void
    {
        if ($user instanceof PasswordUpgradableInterface) {
            $user->upgradePasswordHash($hash);
            $this->entities->flush();
        }
    }

    /**
     * @param array<array-key, mixed> $config auth.users
     */
    public static function fromConfig(EntityManagerInterface $entities, array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['driver', 'entity', 'login'], true)) {
                throw new InvalidArgumentException("Unknown option auth.users.{$key} for the doctrine driver. Known options: driver, entity, login.");
            }
        }
        $entity = $config['entity'] ?? null;
        if (!is_string($entity) || !class_exists($entity) || !is_a($entity, UserInterface::class, true)) {
            throw new InvalidArgumentException('auth.users.entity must name an entity class implementing ' . UserInterface::class . ', such as App\Entity\User::class.');
        }
        $login = $config['login'] ?? 'email';
        if (!is_string($login) || !$entities->getClassMetadata($entity)->hasField($login)) {
            throw new InvalidArgumentException("auth.users.login must be a mapped field of {$entity}, such as email.");
        }

        return new self($entities, $entity, $login);
    }
}
