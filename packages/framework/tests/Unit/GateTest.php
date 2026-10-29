<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Auth\Access\AccessDeniedException;
use Crystoline\Xpsr\Auth\Access\Gate;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use PHPUnit\Framework\TestCase;

final class GateTest extends TestCase
{
    private Gate $gate;
    private GenericUser $owner;
    private GenericUser $other;
    private GenericUser $admin;

    protected function setUp(): void
    {
        $this->gate = new Gate(new ServiceContainer(), [GateTask::class => GateTaskPolicy::class]);
        $this->owner = new GenericUser(1, null);
        $this->other = new GenericUser(2, null);
        $this->admin = new GenericUser(3, null, ['admin' => true]);
    }

    public function testPoliciesDecideForTheirModel(): void
    {
        $task = new GateTask(ownerId: 1);
        self::assertTrue($this->gate->allows($this->owner, 'update', $task));
        self::assertFalse($this->gate->allows($this->other, 'update', $task));
        self::assertTrue($this->gate->denies($this->other, 'update', $task));
        self::assertTrue($this->gate->allows($this->owner, 'create', GateTask::class), 'A class name calls the method without a model.');
        self::assertTrue($this->gate->allows($this->owner, 'view-any', GateTask::class), 'view-any calls viewAny().');
    }

    public function testBeforeDecidesFirst(): void
    {
        self::assertTrue($this->gate->allows($this->admin, 'delete', new GateTask(ownerId: 1)), 'before() lets admins do anything.');
        self::assertFalse($this->gate->allows($this->owner, 'delete', new GateTask(ownerId: 1)), 'Otherwise delete() decides.');
    }

    public function testGuestsAreRefusedUnlessTheMethodAcceptsNull(): void
    {
        self::assertFalse($this->gate->allows(null, 'update', new GateTask(ownerId: 1)));
        self::assertTrue($this->gate->allows(null, 'view', new GateTask(ownerId: 1, public: true)));
        self::assertFalse($this->gate->allows(null, 'view', new GateTask(ownerId: 1)));
    }

    public function testSubclassesAndInterfacesUseTheirParentsPolicy(): void
    {
        self::assertTrue($this->gate->allows($this->owner, 'update', new GateUrgentTask(ownerId: 1)));
        $this->gate->policy(GateOwnedInterface::class, GateOwnedPolicy::class);
        self::assertTrue($this->gate->allows($this->owner, 'archive', new GateNote()));
    }

    public function testDefinedAbilitiesNeedNoModel(): void
    {
        $this->gate->define('view-reports', static fn(GenericUser $user): bool => $user->get('admin') === true);
        self::assertTrue($this->gate->allows($this->admin, 'view-reports'));
        self::assertFalse($this->gate->allows($this->owner, 'view-reports'));
        self::assertFalse($this->gate->allows(null, 'view-reports'));
    }

    public function testAnythingWithoutAPolicyIsRefused(): void
    {
        self::assertFalse($this->gate->allows($this->admin, 'update', new \stdClass()));
        self::assertFalse($this->gate->allows($this->admin, 'fly', new GateTask(ownerId: 3)), 'When before() does not decide, a missing fly() method refuses.');
    }

    public function testAuthorizeThrowsA403(): void
    {
        $this->gate->authorize($this->owner, 'update', new GateTask(ownerId: 1));
        try {
            $this->gate->authorize($this->other, 'update', new GateTask(ownerId: 1));
            self::fail('The action was allowed.');
        } catch (AccessDeniedException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertSame('update', $exception->ability);
            self::assertSame('You are not allowed to do this.', $exception->publicMessage());
        }
    }
}

class GateTask
{
    public function __construct(public readonly int $ownerId, public readonly bool $public = false) {}
}

final class GateUrgentTask extends GateTask {}

interface GateOwnedInterface {}

final class GateNote implements GateOwnedInterface {}

final class GateOwnedPolicy
{
    public function archive(UserInterface $user, GateOwnedInterface $note): bool
    {
        return true;
    }
}

final class GateTaskPolicy
{
    public function before(GenericUser $user, string $ability): ?bool
    {
        return $user->get('admin') === true && $ability !== 'fly' ? true : null;
    }

    public function view(?GenericUser $user, GateTask $task): bool
    {
        return $task->public || $user?->authIdentifier() === $task->ownerId;
    }

    public function viewAny(GenericUser $user): bool
    {
        return true;
    }

    public function create(GenericUser $user): bool
    {
        return true;
    }

    public function update(GenericUser $user, GateTask $task): bool
    {
        return $user->authIdentifier() === $task->ownerId;
    }

    public function delete(GenericUser $user, GateTask $task): bool
    {
        return false;
    }
}
