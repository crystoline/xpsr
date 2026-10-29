<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests;

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Orm\Doctrine\Auth\DoctrineUserProvider;
use Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\AuthEntity\Member;
use Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\Project;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** auth.users driver "doctrine": user entities, found by ID and login, with hash upgrades. */
final class DoctrineUserProviderTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project(['entities' => [__DIR__ . '/Fixtures/AuthEntity']]);
        $config = $this->project->application->getConfig();
        $config->set('app.registry', [...$config->get('app.registry'), AuthServiceRegistry::class]);
        $config->set('auth', ['users' => ['driver' => 'doctrine', 'entity' => Member::class, 'login' => 'email']]);
        $app = $this->project->boot();
        $em = $app->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->persist(new Member('ada@example.com', password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4])));
        $em->flush();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->project->application->get(ConnectionManager::class)->connection()->close();
        $this->project->remove();
    }

    public function testUsersAreEntities(): void
    {
        $users = $this->project->application->get(AuthManager::class)->users();
        self::assertInstanceOf(DoctrineUserProvider::class, $users);
        $ada = $users->findByLogin('ada@example.com');
        self::assertInstanceOf(Member::class, $ada);
        self::assertSame($ada, $users->findById($ada->authIdentifier()));
        self::assertNull($users->findByLogin('nobody@example.com'));
        self::assertNull($users->findById(99));
    }

    public function testSigningInUpgradesTheEntitysHash(): void
    {
        $app = $this->project->application;
        self::assertNotNull($app->get(AuthManager::class)->verify('ada@example.com', 'correct horse'));
        $em = $app->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->getRepository(Member::class)->findOneBy(['email' => 'ada@example.com']);
        self::assertInstanceOf(Member::class, $stored);
        self::assertFalse((new PasswordHasher())->needsRehash($stored->passwordHash), 'The upgraded hash was flushed.');
    }

    public function testTheEntityAndLoginFieldAreChecked(): void
    {
        $em = $this->project->application->get(EntityManagerInterface::class);
        try {
            DoctrineUserProvider::fromConfig($em, ['driver' => 'doctrine', 'entity' => \stdClass::class]);
            self::fail('A class that is not a user was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('auth.users.entity must name an entity class implementing', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('auth.users.login must be a mapped field of ' . Member::class);
        DoctrineUserProvider::fromConfig($em, ['driver' => 'doctrine', 'entity' => Member::class, 'login' => 'username']);
    }
}
