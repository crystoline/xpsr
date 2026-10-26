<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Console\Commands\MakeCommandCommand;
use Crystoline\Console\Generator\GeneratorException;
use Crystoline\Console\Generator\StubGenerator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Crystoline\Tests\Fixtures\TemporaryDirectory;

final class StubGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TemporaryDirectory::create('stubs');
        mkdir($this->root . '/app');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    public function testResolvesNestedNamesToPsr4Paths(): void
    {
        $generator = new StubGenerator($this->root, 'Acme\\Shop\\', 'src/');
        foreach (['Admin/UserController', 'Admin\UserController', ' Admin/UserController '] as $name) {
            $target = $generator->resolve('Http\Controller', $name);
            self::assertSame('Acme\Shop\Http\Controller\Admin', $target->namespace);
            self::assertSame('UserController', $target->shortName);
            self::assertSame('Acme\Shop\Http\Controller\Admin\UserController', $target->className());
            self::assertSame('src/Http/Controller/Admin/UserController.php', $target->relativePath);
            self::assertSame($this->root . '/src/Http/Controller/Admin/UserController.php', $target->path);
        }
        self::assertSame('App\Thing', (new StubGenerator($this->root))->resolve('', 'Thing')->className());
    }

    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'parent directory' => ['../Escape'];
        yield 'absolute path' => ['/Escape'];
        yield 'leading namespace separator' => ['\Escape'];
        yield 'trailing separator' => ['Admin/'];
        yield 'empty segment' => ['Admin//User'];
        yield 'space' => ['User Controller'];
        yield 'leading digit' => ['9Lives'];
        yield 'file extension' => ['User.php'];
        yield 'non-ASCII' => ['Usér'];
        yield 'reserved class name' => ['Class'];
        yield 'reserved namespace segment' => ['List/Users'];
        yield 'reserved type name' => ['Admin/String'];
        yield 'Windows device name' => ['Con'];
        yield 'numbered Windows device name' => ['Lpt1'];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidClassNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StubGenerator($this->root))->resolve('Http\Controller', $name);
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'empty namespace' => ['', 'app'];
        yield 'invalid namespace' => ['My-App', 'app'];
        yield 'absolute source directory' => ['App', '/app'];
        yield 'parent source directory' => ['App', '../app'];
        yield 'nested parent source directory' => ['App', 'app/../../elsewhere'];
        yield 'Windows absolute source directory' => ['App', 'C:\app'];
        yield 'empty source directory' => ['App', ''];
    }

    #[DataProvider('invalidConfiguration')]
    public function testRejectsInvalidConfiguration(string $namespace, string $sourceDir): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StubGenerator($this->root, $namespace, $sourceDir);
    }

    public function testWritesRenderedStubAndCreatesDirectories(): void
    {
        $generator = new StubGenerator($this->root);
        $target = $generator->resolve('Http\Middleware', 'Api/RequireJson');
        self::assertFalse($generator->write($target, 'middleware'));
        $contents = (string) file_get_contents($target->path);
        self::assertStringContainsString("namespace App\Http\Middleware\Api;\n", $contents);
        self::assertStringContainsString('final class RequireJson implements MiddlewareInterface', $contents);
        self::assertStringNotContainsString('{{', $contents);
    }

    public function testConflictsLeaveExistingFilesUnlessForced(): void
    {
        $generator = new StubGenerator($this->root);
        $target = $generator->resolve('Registry', 'BillingServiceRegistry');
        mkdir(dirname($target->path));
        file_put_contents($target->path, 'sentinel');
        try {
            $generator->write($target, 'provider');
            self::fail('An existing file must not be overwritten.');
        } catch (GeneratorException $exception) {
            self::assertStringContainsString('app/Registry/BillingServiceRegistry.php already exists', $exception->getMessage());
        }
        self::assertSame('sentinel', file_get_contents($target->path));
        self::assertTrue($generator->write($target, 'provider', force: true));
        self::assertStringContainsString('class BillingServiceRegistry extends ServiceRegistry', (string) file_get_contents($target->path));
        self::assertSame(['app/Registry/BillingServiceRegistry.php'], TemporaryDirectory::files($this->root), 'No temporary files remain.');
    }

    public function testRefusesDirectoriesAtTheTargetPath(): void
    {
        $generator = new StubGenerator($this->root);
        $target = $generator->resolve('', 'Thing');
        mkdir($target->path);
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('is a directory');
        $generator->write($target, 'provider', force: true);
    }

    public function testRequiresAnExistingSourceDirectory(): void
    {
        $generator = new StubGenerator($this->root, 'App', 'missing');
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('missing/ does not exist');
        $generator->write($generator->resolve('', 'Thing'), 'provider');
    }

    public function testRefusesSymbolicLinksThatEscapeTheSourceDirectory(): void
    {
        $outside = TemporaryDirectory::create('outside');
        try {
            if (!@symlink($outside, $this->root . '/app/Http')) {
                self::markTestSkipped('Symbolic links are not available.');
            }
            $generator = new StubGenerator($this->root);
            foreach (['Escaped', 'Nested/Escaped'] as $name) {
                try {
                    $generator->write($generator->resolve('Http', $name), 'provider', force: true);
                    self::fail('A symbolic link must not let the generator write outside app/.');
                } catch (GeneratorException $exception) {
                    self::assertStringContainsString('resolves outside app/', $exception->getMessage());
                }
            }
            self::assertSame([], TemporaryDirectory::files($outside));
        } finally {
            TemporaryDirectory::remove($outside);
        }
    }

    public function testRefusesToReplaceASymbolicLinkTarget(): void
    {
        $outsideFile = $this->root . '/outside.php';
        file_put_contents($outsideFile, 'sentinel');
        if (!@symlink($outsideFile, $this->root . '/app/Linked.php')) {
            self::markTestSkipped('Symbolic links are not available.');
        }
        $generator = new StubGenerator($this->root);
        try {
            $generator->write($generator->resolve('', 'Linked'), 'provider', force: true);
            self::fail('A symbolic link target must not be replaced.');
        } catch (GeneratorException $exception) {
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        }
        self::assertSame('sentinel', file_get_contents($outsideFile));
    }

    public function testStubPlaceholdersMustAllHaveValues(): void
    {
        $stubs = TemporaryDirectory::create('stub templates');
        try {
            file_put_contents($stubs . '/custom.stub', '<?php // {{ class }} {{ missing_value }}');
            $generator = new StubGenerator($this->root, stubDir: $stubs);
            $target = $generator->resolve('', 'Thing');
            try {
                $generator->write($target, 'custom');
                self::fail('A stub with an unfilled placeholder must not be written.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('missing_value', $exception->getMessage());
            }
            self::assertFileDoesNotExist($target->path);

            // Values may contain placeholder-like text; only the template is checked.
            self::assertFalse($generator->write($target, 'custom', ['missing_value' => '{{ literal }}']));
            self::assertSame('<?php // Thing {{ literal }}', file_get_contents($target->path));
        } finally {
            TemporaryDirectory::remove($stubs);
        }
    }

    public function testUnknownStubFails(): void
    {
        $generator = new StubGenerator($this->root);
        $this->expectException(GeneratorException::class);
        $generator->write($generator->resolve('', 'Thing'), 'unknown');
    }

    public static function commandNames(): iterable
    {
        yield ['SendReportCommand', 'app:send-report'];
        yield ['SendReport', 'app:send-report'];
        yield ['HTMLExportCommand', 'app:html-export'];
        yield ['Report2Pdf', 'app:report2-pdf'];
        yield ['Command', 'app:command'];
        yield ['snake_case_Thing', 'app:snake-case-thing'];
    }

    #[DataProvider('commandNames')]
    public function testDerivesConsoleNamesFromClassNames(string $class, string $expected): void
    {
        self::assertSame($expected, MakeCommandCommand::deriveName($class));
    }
}
