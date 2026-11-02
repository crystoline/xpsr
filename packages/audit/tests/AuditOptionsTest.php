<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests;

use Crystoline\Xpsr\Audit\AuditOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditOptionsTest extends TestCase
{
    public function testDefaultsAndValues(): void
    {
        $defaults = AuditOptions::fromArray([]);
        self::assertSame('audit_log', $defaults->table);
        self::assertNull($defaults->connection);
        self::assertSame(['created_at', 'updated_at', 'deleted_at'], $defaults->except);
        self::assertSame(['password', 'password_hash', 'remember_token', 'secret', 'token'], $defaults->redact);

        $options = AuditOptions::fromArray(['table' => 'activity', 'connection' => 'logs', 'except' => [], 'redact' => ['pin']]);
        self::assertSame('activity', $options->table);
        self::assertSame('logs', $options->connection);
        self::assertSame([], $options->except);
        self::assertSame(['pin'], $options->redact);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown key' => [['tables' => 'x'], 'Unknown option audit.tables. Known options: table, connection, except, redact.'];
        yield 'table' => [['table' => 'audit-log'], 'audit.table must be a plain table name'];
        yield 'connection' => [['connection' => ''], 'audit.connection must name a database connection'];
        yield 'except' => [['except' => 'notes'], 'audit.except must be a list of field names.'];
        yield 'redact' => [['redact' => ['']], 'audit.redact must be a list of field names.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalid')]
    public function testInvalidConfigurationIsRefused(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        AuditOptions::fromArray($config);
    }
}
