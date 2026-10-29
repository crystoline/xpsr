<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests;

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Database\Auth\SanctumTokenRepository;
use Crystoline\Xpsr\Orm\Eloquent\ConnectionResolver;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\Project;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\SanctumMember;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * XPSR's sanctum guard and Laravel Sanctum 4 itself on one personal_access_tokens table, created
 * by Sanctum's own migration: each accepts the other's tokens.
 */
final class SanctumCompatibilityTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $config = $this->project->application->getConfig();
        // The guard's tokenable_type comes from its provider's model, as Sanctum checks it.
        $config->set('auth.guards', ['api' => ['driver' => 'sanctum', 'provider' => 'users']]);
        $config->set('auth.providers', ['users' => ['driver' => 'eloquent', 'model' => SanctumMember::class, 'login' => 'email']]);
        $this->project->boot();

        // What Laravel provides Sanctum with: its configuration and the schema builder.
        Container::getInstance()->instance('config', new Repository(['sanctum' => ['token_prefix' => '']]));
        $connection = $this->project->application->get(ConnectionResolver::class)->connection();
        Schema::swap($connection->getSchemaBuilder());
        $schema = $connection->getSchemaBuilder();
        foreach (['personal_access_tokens', 'sanctum_members'] as $table) {
            $schema->dropIfExists($table);
        }
        $migrations = dirname((string) (new ReflectionClass(PersonalAccessToken::class))->getFileName(), 2) . '/database/migrations';
        foreach (glob($migrations . '/*.php') ?: [] as $file) {
            (require $file)->up();
        }
        $schema->create('sanctum_members', static function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
        SanctumMember::query()->create(['email' => 'ada@example.com', 'password' => 'x']);
    }

    protected function tearDown(): void
    {
        $schema = $this->project->application->get(ConnectionResolver::class)->connection()->getSchemaBuilder();
        $schema->dropIfExists('personal_access_tokens');
        $schema->dropIfExists('sanctum_members');
        Schema::clearResolvedInstance('db.schema');
        Container::setInstance(null);
        $this->project->remove();
    }

    public function testXpsrAcceptsTokensThatSanctumCreated(): void
    {
        $member = SanctumMember::query()->firstOrFail();
        $laravel = $member->createToken('mobile app', ['payments:create']);

        $identity = $this->project->application->get(AuthManager::class)->guard('api')->authenticate(
            new ServerRequest('POST', '/api/payments', ['Authorization' => 'Bearer ' . $laravel->plainTextToken]),
        );
        self::assertNotNull($identity);
        self::assertSame($member->id, $identity->user->authIdentifier());
        self::assertSame(['payments:create'], $identity->scopes());
        self::assertSame((string) $laravel->accessToken->getKey(), $identity->tokenId);
    }

    public function testSanctumAcceptsTokensThatXpsrCreated(): void
    {
        $member = SanctumMember::query()->firstOrFail();
        $tokens = $this->project->application->get(SanctumTokenRepository::class);
        self::assertInstanceOf(SanctumTokenRepository::class, $tokens);
        $new = $tokens->create($member, 'console', ['payments:read']);

        $found = PersonalAccessToken::findToken($new->plainText);
        self::assertInstanceOf(PersonalAccessToken::class, $found);
        self::assertTrue($found->can('payments:read'));
        self::assertFalse($found->can('refunds:create'));
        self::assertSame('console', $found->getAttribute('name'));
        $tokenable = $found->getAttribute('tokenable');
        self::assertInstanceOf(SanctumMember::class, $tokenable, 'tokenable_type is the model class Sanctum resolves.');
        self::assertSame($member->id, $tokenable->id);
        self::assertSame([$new->token->id], $member->tokens()->pluck('id')->map(static fn(mixed $id): int => (int) $id)->all());
    }
}
