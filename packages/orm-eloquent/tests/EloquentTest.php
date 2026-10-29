<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests;

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Orm\Eloquent\Auth\EloquentUserProvider;
use Crystoline\Xpsr\Orm\Eloquent\ConnectionResolver;
use Crystoline\Xpsr\Orm\Eloquent\Console\MakeModelCommand;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\ContractRecord;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\Member;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\OtherRecord;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\Project;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class EloquentTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->application->getConfig()->set('auth.users', ['driver' => 'eloquent', 'model' => Member::class, 'login' => 'email']);
        $this->project->boot();
        $schema = $this->project->db()->createSchemaManager();
        foreach (['orm_contract_records', 'eloquent_members'] as $table) {
            if ($schema->tableExists($table)) {
                $schema->dropTable($table);
            }
        }
        $this->project->db()->executeStatement('CREATE TABLE orm_contract_records (id INTEGER NOT NULL, label VARCHAR(100) NOT NULL, PRIMARY KEY (id))');
        $schema->createTable(Table::editor()
            ->setUnquotedName('eloquent_members')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('email')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('password')->setTypeName(Types::STRING)->setLength(255)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create());
    }

    protected function tearDown(): void
    {
        $schema = $this->project->db()->createSchemaManager();
        $schema->dropTable('orm_contract_records');
        $schema->dropTable('eloquent_members');
        $this->project->remove();
    }

    public function testModelsUseXpsrsConnectionsByName(): void
    {
        $resolver = $this->project->application->get(ConnectionResolver::class);
        self::assertSame('main', $resolver->getDefaultConnection());
        self::assertSame($this->project->db()->getNativeConnection(), $resolver->connection()->getPdo(), 'Eloquent queries the PDO XPSR opened.');
        self::assertSame($resolver->connection(), $this->project->application->get(ConnectionInterface::class));

        $this->project->db('other')->executeStatement('CREATE TABLE other_records (id INTEGER PRIMARY KEY, label VARCHAR(50))');
        OtherRecord::query()->create(['label' => 'elsewhere']);
        self::assertSame('elsewhere', $this->project->db('other')->fetchOne('SELECT label FROM other_records'));
        self::assertSame($this->project->db('other')->getNativeConnection(), $resolver->connection('other')->getPdo());
    }

    public function testEloquentTransactionsNestInsideXpsrTransactions(): void
    {
        $db = $this->project->db();
        $eloquent = $this->project->application->get(ConnectionInterface::class);

        $db->beginTransaction();
        $eloquent->transaction(static fn() => ContractRecord::query()->create(['id' => 1, 'label' => 'inner']));
        self::assertSame(1, $db->getTransactionNestingLevel(), 'The Eloquent transaction committed only its own level.');
        try {
            $eloquent->transaction(static function (): void {
                ContractRecord::query()->create(['id' => 2, 'label' => 'failed']);
                throw new RuntimeException('Undo this one.');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(['1'], array_map('strval', $db->fetchFirstColumn('SELECT id FROM orm_contract_records')), 'A failed Eloquent transaction rolls back to its savepoint.');
        $db->rollBack();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM orm_contract_records'), 'XPSR\'s rollback undoes the Eloquent write.');
    }

    public function testXpsrTransactionsNestInsideEloquentTransactions(): void
    {
        $db = $this->project->db();
        $eloquent = $this->project->application->get(ConnectionInterface::class);
        $eloquent->beginTransaction();
        $db->transactional(static fn() => $db->insert('orm_contract_records', ['id' => 3, 'label' => 'dbal']));
        self::assertSame(1, $eloquent->transactionLevel());
        $eloquent->rollBack();
        self::assertSame(0, $db->getTransactionNestingLevel());
        self::assertNull(ContractRecord::query()->find(3));

        $eloquent->transaction(static fn() => ContractRecord::query()->create(['id' => 4, 'label' => 'kept']));
        self::assertSame('kept', $db->fetchOne('SELECT label FROM orm_contract_records WHERE id = 4'));
        self::assertSame(0, $db->getTransactionNestingLevel());
    }

    public function testModelEventsAndTheQueryBuilderWork(): void
    {
        Member::query()->create(['email' => 'Ada@Example.com', 'password' => 'x']);
        self::assertSame('ada@example.com', Member::query()->value('email'), 'The creating event ran.');
        self::assertSame(1, $this->project->application->get(ConnectionInterface::class)->table('eloquent_members')->where('email', 'ada@example.com')->count());
        self::assertArrayNotHasKey('password', Member::query()->firstOrFail()->toArray());
    }

    public function testModelsCanBeTheUsersOfConfigAuth(): void
    {
        $member = Member::query()->create(['email' => 'ada@example.com', 'password' => password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4])]);
        $auth = $this->project->application->get(AuthManager::class);
        self::assertInstanceOf(EloquentUserProvider::class, $auth->users());
        self::assertSame($member->id, $auth->users()->findById($member->id)?->authIdentifier());

        self::assertNotNull($auth->verify('ada@example.com', 'correct horse'));
        $stored = (string) $this->project->db()->fetchOne('SELECT password FROM eloquent_members');
        self::assertFalse((new PasswordHasher())->needsRehash($stored), 'The model saved the upgraded hash.');
        self::assertNull($auth->users()->findByLogin('nobody@example.com'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('auth.users.model must name an Eloquent model implementing');
        EloquentUserProvider::fromConfig(['driver' => 'eloquent', 'model' => ContractRecord::class]);
    }

    public function testMakeModelWritesAModelForItsTable(): void
    {
        self::assertArrayHasKey('make:model', $this->project->application->get(CommandRegistry::class)->all());
        $tester = new CommandTester($this->project->application->get(MakeModelCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'Billing/InvoiceLine']), $tester->getDisplay());
        $file = (string) file_get_contents($this->project->root . '/app/Model/Billing/InvoiceLine.php');
        self::assertStringContainsString('namespace App\Model\Billing;', $file);
        self::assertStringContainsString("class InvoiceLine extends Model\n", $file);
        self::assertStringContainsString("protected \$table = 'invoice_lines';", $file);
        self::assertStringContainsString('php xpsr make:migration create_invoice_lines_table --create=invoice_lines', $tester->getDisplay());
    }
}
