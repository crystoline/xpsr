<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests;

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Crystoline\Xpsr\Orm\Cycle\Auth\CycleUserProvider;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\AuthEntity\Member;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Project;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** auth.users driver "cycle": user entities, found by ID and login, with hash upgrades. */
final class CycleUserProviderTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project(['entities' => [__DIR__ . '/Fixtures/AuthEntity']], auth: true);
        $this->project->application->getConfig()->set('auth', ['users' => ['driver' => 'cycle', 'entity' => Member::class, 'login' => 'email']]);
        $this->project->boot();
        $this->project->migrateEntities();
        $this->project->em()->persist(new Member('ada@example.com', password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4])))->run();
        $this->project->reset();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testUsersAreEntities(): void
    {
        $users = $this->project->application->get(AuthManager::class)->users();
        self::assertInstanceOf(CycleUserProvider::class, $users);
        $ada = $users->findByLogin('ada@example.com');
        self::assertInstanceOf(Member::class, $ada);
        self::assertSame($ada, $users->findById($ada->authIdentifier()));
        self::assertNull($users->findByLogin('nobody@example.com'));
        self::assertNull($users->findByLogin(''));
        self::assertNull($users->findById(99));
    }

    public function testSigningInUpgradesTheEntitysHash(): void
    {
        $app = $this->project->application;
        self::assertNotNull($app->get(AuthManager::class)->verify('ada@example.com', 'correct horse'));
        $this->project->reset();
        $stored = $this->project->orm()->getRepository(Member::class)->findOne(['email' => 'ada@example.com']);
        self::assertInstanceOf(Member::class, $stored);
        self::assertFalse((new PasswordHasher())->needsRehash($stored->passwordHash), 'The upgraded hash was saved.');
    }

    public function testTheEntityAndLoginFieldAreChecked(): void
    {
        $orm = $this->project->orm();
        $em = $this->project->em();
        try {
            CycleUserProvider::fromConfig($orm, $em, ['driver' => 'cycle', 'entity' => \stdClass::class]);
            self::fail('A class that is not a user was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('auth.users.entity must name an entity class implementing', $exception->getMessage());
        }
        try {
            CycleUserProvider::fromConfig($orm, $em, ['driver' => 'cycle', 'entity' => Member::class, 'token' => 'x']);
            self::fail('An unknown option was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Unknown option auth.users.token for the cycle driver.', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('auth.users.login must be a mapped field of ' . Member::class);
        CycleUserProvider::fromConfig($orm, $em, ['driver' => 'cycle', 'entity' => Member::class, 'login' => 'username']);
    }
}
