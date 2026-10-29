<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Auth;

use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Users as Eloquent models (auth.users driver "eloquent"):
 *
 *   auth.users = ['driver' => 'eloquent', 'model' => App\Model\User::class, 'login' => 'email', 'password' => 'password']
 *
 * The model implements Crystoline\Xpsr\Auth\UserInterface, for example with the Authenticatable
 * trait. An outdated password hash is replaced in the "password" column at sign-in.
 */
final class EloquentUserProvider implements UserProviderInterface
{
    private const COLUMN = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /** @param class-string<Model&UserInterface> $model */
    public function __construct(
        private readonly string $model,
        private readonly string $loginColumn = 'email',
        private readonly string $passwordColumn = 'password',
    ) {}

    /** @param array<array-key, mixed> $config auth.users */
    public static function fromConfig(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['driver', 'model', 'login', 'password'], true)) {
                throw new InvalidArgumentException("Unknown option auth.users.{$key} for the eloquent driver. Known options: driver, model, login, password.");
            }
        }
        $model = $config['model'] ?? null;
        if (!is_string($model) || !is_a($model, Model::class, true) || !is_a($model, UserInterface::class, true)) {
            throw new InvalidArgumentException('auth.users.model must name an Eloquent model implementing ' . UserInterface::class . ', such as App\Model\User::class.');
        }
        $login = $config['login'] ?? 'email';
        $password = $config['password'] ?? 'password';
        if (!is_string($login) || !preg_match(self::COLUMN, $login) || !is_string($password) || !preg_match(self::COLUMN, $password)) {
            throw new InvalidArgumentException('auth.users.login and auth.users.password must be column names.');
        }

        return new self($model, $login, $password);
    }

    public function findById(string|int $id): ?UserInterface
    {
        $user = $this->model::query()->find($id);

        return $user instanceof UserInterface ? $user : null;
    }

    public function findByLogin(string $login): ?UserInterface
    {
        $user = $login === '' ? null : $this->model::query()->where($this->loginColumn, $login)->first();

        return $user instanceof UserInterface ? $user : null;
    }

    public function updatePasswordHash(UserInterface $user, string $hash): void
    {
        if ($user instanceof Model) {
            $user->forceFill([$this->passwordColumn => $hash])->save();
        }
    }
}
