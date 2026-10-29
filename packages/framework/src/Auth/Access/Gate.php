<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Access;

use Closure;
use Crystoline\Xpsr\Auth\UserInterface;
use Psr\Container\ContainerInterface;
use ReflectionFunction;

/**
 * Decides what users may do, with policies: one class per kind of record, one method per action.
 *
 *     final class TaskPolicy
 *     {
 *         public function update(User $user, Task $task): bool { return $task->ownerId === $user->id; }
 *         public function create(User $user): bool { return true; }
 *     }
 *
 *     $gate->allows($user, 'update', $task);           // TaskPolicy::update($user, $task)
 *     $gate->allows($user, 'create', Task::class);     // TaskPolicy::create($user)
 *     $gate->authorize($user, 'update', $task);        // throws AccessDeniedException (403) when refused
 *
 * Policies are listed in auth.policies (model class => policy class) and built by the container.
 * An ability such as "view-any" calls viewAny(). A policy's before($user, $ability) runs first;
 * returning true or false decides, null continues. Guests are refused unless the method's user
 * parameter accepts null. Anything without a policy method or defined ability is refused.
 */
final class Gate
{
    /** @var array<string, Closure> */
    private array $abilities = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @param array<class-string, class-string> $policies */
    public function __construct(private readonly ContainerInterface $container, private array $policies = []) {}

    /** An ability that is not about one kind of record: define('view-reports', fn(?User $user): bool => ...). */
    public function define(string $ability, callable $check): void
    {
        $this->abilities[$ability] = $check(...);
    }

    /** @param class-string $model @param class-string $policy */
    public function policy(string $model, string $policy): void
    {
        $this->policies[$model] = $policy;
        unset($this->instances[$model]);
    }

    public function allows(?UserInterface $user, string $ability, mixed ...$arguments): bool
    {
        if (isset($this->abilities[$ability])) {
            return self::call($this->abilities[$ability], $user, $arguments);
        }
        $subject = $arguments[0] ?? null;
        $class = is_object($subject) ? $subject::class : (is_string($subject) && class_exists($subject) ? $subject : null);
        $policy = $class === null ? null : $this->policyFor($class);
        if ($policy === null) {
            return false;
        }
        if (is_string($subject)) {
            array_shift($arguments);
        }
        if (method_exists($policy, 'before')) {
            $before = self::call($policy->before(...), $user, [$ability], allowNull: true);
            if ($before !== null) {
                return $before;
            }
        }
        $method = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $ability))));

        return method_exists($policy, $method) && self::call($policy->{$method}(...), $user, $arguments);
    }

    public function denies(?UserInterface $user, string $ability, mixed ...$arguments): bool
    {
        return !$this->allows($user, $ability, ...$arguments);
    }

    /** Throw AccessDeniedException (403) unless the user may. */
    public function authorize(?UserInterface $user, string $ability, mixed ...$arguments): void
    {
        if (!$this->allows($user, $ability, ...$arguments)) {
            throw new AccessDeniedException($ability);
        }
    }

    /** @param class-string $class */
    private function policyFor(string $class): ?object
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }
        foreach ([$class, ...array_values(class_parents($class) ?: []), ...array_values(class_implements($class) ?: [])] as $candidate) {
            if (isset($this->policies[$candidate])) {
                $policy = $this->container->get($this->policies[$candidate]);

                return $this->instances[$class] = is_object($policy) ? $policy : null;
            }
        }

        return null;
    }

    /**
     * Call a check with the user first. A guest gets false (or null for before()) unless the
     * check's first parameter accepts null.
     *
     * @param list<mixed> $arguments
     * @return ($allowNull is true ? bool|null : bool)
     */
    private static function call(Closure $check, ?UserInterface $user, array $arguments, bool $allowNull = false): ?bool
    {
        if ($user === null) {
            $reflection = new ReflectionFunction($check);
            $first = $reflection->getParameters()[0] ?? null;
            if ($first === null || !$first->allowsNull()) {
                return $allowNull ? null : false;
            }
        }
        $result = $check($user, ...$arguments);

        return $allowNull && $result === null ? null : $result === true;
    }
}
