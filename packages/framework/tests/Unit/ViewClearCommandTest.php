<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\Commands\ViewClearCommand;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class ViewClearCommandTest extends TestCase
{
    private string $root;
    private string $cache;

    protected function setUp(): void
    {
        $this->root = TemporaryDirectory::create('view clear');
        $this->cache = $this->root . '/var/cache/views';
        mkdir($this->root . '/config');
        mkdir($this->root . '/views');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    /** @return array{int, string} exit code and output; error blocks are wrapped, so compare them with spaces collapsed */
    private function clear(string|false|null $cache = null): array
    {
        file_put_contents($this->root . '/config/app.php', '<?php return ' . var_export([
            'registry' => [ConsoleServiceRegistry::class],
            'view_dir' => $this->root . '/views',
            'view_cache_dir' => $cache ?? $this->cache,
        ], true) . ';');
        $output = new BufferedOutput();
        $input = new ArrayInput(['command' => 'view:clear']);
        $input->setInteractive(false);
        $status = (new Application($this->root))->getConsoleKernel()->run($input, $output);

        return [$status, $output->fetch()];
    }

    public function testCompiledTemplatesAreRemovedAndTheDirectoryIsKept(): void
    {
        // Twig keeps compiled templates in subdirectories, Latte next to lock files.
        mkdir($this->cache . '/ab', 0777, true);
        file_put_contents($this->cache . '/ab/abcdef.php', '<?php');
        file_put_contents($this->cache . '/home-latte--0123456789abcdef.php', '<?php');
        file_put_contents($this->cache . '/home-latte--0123456789abcdef.php.lock', '');

        self::assertSame([0, "Removed 3 file(s) from the view cache in var/cache/views.\n"], $this->clear());
        self::assertDirectoryExists($this->cache);
        self::assertSame([], TemporaryDirectory::files($this->cache));
    }

    public function testLinksAreRemovedWithoutTouchingWhatTheyPointTo(): void
    {
        mkdir($this->cache, 0777, true);
        mkdir($this->root . '/elsewhere');
        file_put_contents($this->root . '/elsewhere/keep.txt', 'kept');
        if (!@symlink($this->root . '/elsewhere', $this->cache . '/link')) {
            self::markTestSkipped('Needs symbolic links.');
        }

        self::assertSame(0, $this->clear()[0]);
        self::assertFileDoesNotExist($this->cache . '/link');
        self::assertFileExists($this->root . '/elsewhere/keep.txt');
    }

    public function testNothingToClear(): void
    {
        self::assertSame([0, "No view cache is configured (app.view_cache_dir is false).\n"], $this->clear(false));
        self::assertSame([0, "The view cache in var/cache/views is empty.\n"], $this->clear());
    }

    public function testADirectoryHoldingTheApplicationOrItsViewsIsNotEmptied(): void
    {
        foreach ([$this->root, $this->root . '/views'] as $setting) {
            [$status, $output] = $this->clear($setting);
            self::assertSame(1, $status);
            self::assertStringContainsString('so it was not emptied', preg_replace('/\s+/', ' ', $output));
            self::assertFileExists($this->root . '/config/app.php');
        }
    }

    public function testFilesThatCannotBeRemovedAreReported(): void
    {
        mkdir($this->cache . '/ab', 0777, true);
        file_put_contents($this->cache . '/ab/owned-by-another-user.php', '<?php');
        file_put_contents($this->cache . '/removable.php', '<?php');
        $application = new Application($this->root);
        $application->getConfig()->set('app.view_cache_dir', $this->cache);
        $application->getConfig()->set('app.view_dir', $this->root . '/views');
        // Permissions cannot stop root, which runs the tests in containers, so unlink() is replaced.
        $unlink = static fn(string $path): bool => !str_contains($path, 'owned-by-another-user') && unlink($path);
        $tester = new CommandTester(new ViewClearCommand($application->getConfig(), $application, $unlink));

        self::assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        $error = (string) preg_replace('/\s+/', ' ', $tester->getErrorOutput());
        self::assertStringContainsString('Could not remove 1 file(s) from the view cache in var/cache/views, such as', $error);
        self::assertStringContainsString('owned-by-another-user.php', $error);
        self::assertSame(['ab/owned-by-another-user.php'], TemporaryDirectory::files($this->cache), 'The rest is removed, and the directory holding the file stays.');
    }
}
