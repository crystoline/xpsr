<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Console\Setup\ComposerPackageInspector;
use Crystoline\Console\Setup\EnvironmentFile;
use Crystoline\Console\Setup\FileTransaction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Crystoline\Tests\Fixtures\TemporaryDirectory;

final class SetupFilesTest extends TestCase
{
    public static function environmentEdits(): iterable
    {
        yield 'empty file' => ['', "ROUTER_ADAPTER=sunrise\n"];
        yield 'append and keep lines' => ["# App\nAPP_NAME=Mine\n", "# App\nAPP_NAME=Mine\nROUTER_ADAPTER=sunrise\n"];
        yield 'replace in place' => ["A=1\nROUTER_ADAPTER=fastroute\nB=2\n", "A=1\nROUTER_ADAPTER=sunrise\nB=2\n"];
        yield 'replace quoted value with spaces' => ["ROUTER_ADAPTER = \"fastroute\" # note\n", "ROUTER_ADAPTER=sunrise\n"];
        yield 'keep export prefix' => ["  export ROUTER_ADAPTER=fastroute\n", "  export ROUTER_ADAPTER=sunrise\n"];
        yield 'replace every duplicate' => ["ROUTER_ADAPTER=a\nROUTER_ADAPTER=b\n", "ROUTER_ADAPTER=sunrise\nROUTER_ADAPTER=sunrise\n"];
        yield 'similar names untouched' => ["MY_ROUTER_ADAPTER=x\nROUTER_ADAPTER_X=y\n", "MY_ROUTER_ADAPTER=x\nROUTER_ADAPTER_X=y\nROUTER_ADAPTER=sunrise\n"];
        yield 'commented line untouched' => ["#ROUTER_ADAPTER=x\n", "#ROUTER_ADAPTER=x\nROUTER_ADAPTER=sunrise\n"];
        yield 'missing final newline' => ['A=1', "A=1\nROUTER_ADAPTER=sunrise\n"];
        yield 'Windows line endings' => ["A=1\r\nROUTER_ADAPTER=x\r\n", "A=1\r\nROUTER_ADAPTER=sunrise\r\n"];
        yield 'blank lines kept' => ["A=1\n\nB=2\n", "A=1\n\nB=2\nROUTER_ADAPTER=sunrise\n"];
    }

    #[DataProvider('environmentEdits')]
    public function testSetsEnvironmentValuesAndKeepsOtherLines(string $before, string $after): void
    {
        self::assertSame($after, EnvironmentFile::set($before, ['ROUTER_ADAPTER' => 'sunrise']));
    }

    public function testRejectsUnsafeEnvironmentKeysAndValues(): void
    {
        foreach ([['bad-key' => 'x'], ['KEY' => 'two words'], ['KEY' => "x\nINJECTED=1"], ['KEY' => '$HOME']] as $values) {
            try {
                EnvironmentFile::set('', $values);
                self::fail('Unsafe values must be rejected: ' . json_encode($values));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTransactionRestoresChangedFilesAndRemovesCreatedOnes(): void
    {
        $root = TemporaryDirectory::create('transaction');
        try {
            file_put_contents($root . '/existing.txt', 'before');
            chmod($root . '/existing.txt', 0640);
            $transaction = new FileTransaction();
            $transaction->write($root . '/existing.txt', 'after');
            $transaction->write($root . '/existing.txt', 'after again');
            $transaction->write($root . '/created.txt', 'new');
            self::assertSame('after again', file_get_contents($root . '/existing.txt'));
            self::assertSame(0640, fileperms($root . '/existing.txt') & 0777, 'Permissions of replaced files are kept.');
            self::assertSame([], $transaction->rollback());
            self::assertSame('before', file_get_contents($root . '/existing.txt'));
            self::assertSame(['existing.txt'], TemporaryDirectory::files($root), 'Created and temporary files are removed.');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    public function testTransactionRefusesSymbolicLinks(): void
    {
        $root = TemporaryDirectory::create('transaction link');
        try {
            file_put_contents($root . '/target.txt', 'target');
            if (!@symlink($root . '/target.txt', $root . '/.env')) {
                self::markTestSkipped('Symbolic links are not available.');
            }
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('symbolic link');
            (new FileTransaction())->write($root . '/.env', 'changed');
        } finally {
            self::assertSame('target', file_get_contents($root . '/target.txt'));
            TemporaryDirectory::remove($root);
        }
    }

    public function testComposerInspectorCountsOnlyInstalledProductionRequirements(): void
    {
        $root = TemporaryDirectory::create('composer manifest');
        try {
            file_put_contents($root . '/composer.json', json_encode(['require' => [
                'psr/log' => '^3.0',
                'phpunit/phpunit' => '^12.5',
                'vendor/not-installed' => '^1.0',
            ]]));
            $packages = new ComposerPackageInspector($root);
            self::assertTrue($packages->isRequired('psr/log'), 'Listed and installed for production.');
            self::assertFalse($packages->isRequired('phpunit/phpunit'), 'Listed, but installed through require-dev only.');
            self::assertFalse($packages->isRequired('vendor/not-installed'), 'Listed, but not installed.');
            self::assertFalse($packages->isRequired('symfony/console'), 'Installed, but not listed.');
            self::assertFalse((new ComposerPackageInspector($root . '/missing'))->isRequired('psr/log'), 'No composer.json.');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
