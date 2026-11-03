<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Auth;

use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;
use InvalidArgumentException;

/**
 * Users as Cycle entities (auth.users driver "cycle"):
 *
 *   auth.users = ['driver' => 'cycle', 'entity' => App\Entity\User::class, 'login' => 'email']
 *
 * The entity implements Crystoline\Xpsr\Auth\UserInterface. To let sign-in upgrade outdated
 * password hashes, it also implements PasswordUpgradableInterface.
 */
final class CycleUserProvider implements UserProviderInterface
{
    /** @param class-string<UserInterface> $entity */
    public function __construct(
        private readonly ORMInterface $orm,
        private readonly EntityManagerInterface $entities,
        private readonly string $entity,
        private readonly string $loginField = 'email',
    ) {}

    public function findById(string|int $id): ?UserInterface
    {
        $user = $this->orm->getRepository($this->entity)->findByPK($id);

        return $user instanceof UserInterface ? $user : null;
    }

    public function findByLogin(string $login): ?UserInterface
    {
        if ($login === '') {
            return null;
        }
        $user = $this->orm->getRepository($this->entity)->findOne([$this->loginField => $login]);

        return $user instanceof UserInterface ? $user : null;
    }

    public function updatePasswordHash(UserInterface $user, string $hash): void
    {
        if ($user instanceof PasswordUpgradableInterface) {
            $user->upgradePasswordHash($hash);
            $this->entities->persist($user)->run();
        }
    }

    /** @param array<array-key, mixed> $config auth.users */
    public static function fromConfig(ORMInterface $orm, EntityManagerInterface $entities, array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['driver', 'entity', 'login'], true)) {
                throw new InvalidArgumentException("Unknown option auth.users.{$key} for the cycle driver. Known options: driver, entity, login.");
            }
        }
        $entity = $config['entity'] ?? null;
        if (!is_string($entity) || !class_exists($entity) || !is_a($entity, UserInterface::class, true)) {
            throw new InvalidArgumentException('auth.users.entity must name an entity class implementing ' . UserInterface::class . ', such as App\Entity\User::class.');
        }
        $schema = $orm->getSchema();
        $role = $schema->resolveAlias($entity);
        if ($role === null || !$schema->defines($role)) {
            throw new InvalidArgumentException("auth.users.entity: {$entity} is not a mapped entity; is it in a directory of cycle.entities?");
        }
        $login = $config['login'] ?? 'email';
        $columns = $schema->define($role, SchemaInterface::COLUMNS);
        if (!is_string($login) || !is_array($columns) || !array_key_exists($login, $columns)) {
            throw new InvalidArgumentException("auth.users.login must be a mapped field of {$entity}, such as email.");
        }

        return new self($orm, $entities, $entity, $login);
    }
}
