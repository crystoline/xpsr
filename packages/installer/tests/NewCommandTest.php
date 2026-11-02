<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Installer\Tests;

use Crystoline\Xpsr\Installer\NewCommand;
use Crystoline\Xpsr\Installer\Tests\Fixtures\RecordingRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** What "xpsr new" runs, with the programs recorded instead of run. */
final class NewCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/xpsr-new-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            array_map('unlink', (array) glob($this->directory . '/*'));
            rmdir($this->directory);
        }
    }

    /** The output with the line wrapping of the error and warning blocks undone. */
    private static function text(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function execute(RecordingRunner $runner, array $input, bool $interactive = false): CommandTester
    {
        $tester = new CommandTester(new NewCommand($runner));
        $tester->execute($input, ['interactive' => $interactive]);

        return $tester;
    }

    public function testCreatesAProjectWithTheDefaults(): void
    {
        $runner = new RecordingRunner();

        $tester = $this->execute($runner,['name' => $this->directory]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), self::text($tester));
        self::assertSame([
            "/usr/bin/composer create-project crystoline/xpsr {$this->directory} --no-interaction",
            PHP_BINARY . " {$this->directory}/xpsr setup --router=fastroute --preset=web --install-dependencies --no-interaction --composer=/usr/bin/composer",
            PHP_BINARY . " {$this->directory}/xpsr --version",
        ], $runner->texts());
        self::assertNull($runner->commands[0]['directory']);
        self::assertSame($this->directory, $runner->commands[1]['directory'], 'Setup runs inside the new project.');
        $display = self::text($tester);
        self::assertStringContainsString('Creating ' . $this->directory . ' with Composer.', $display);
        self::assertStringContainsString('Setting up: router fastroute, preset web.', $display);
        self::assertStringContainsString('Created ' . basename($this->directory) . ' in ' . $this->directory . ' (XPSR 0.5.0).', $display);
        self::assertStringContainsString('php xpsr serve', $display);
    }

    public function testTheOptionsReachComposerAndSetup(): void
    {
        $runner = new RecordingRunner();

        $tester = $this->execute($runner,[
            'name' => $this->directory, '--router' => 'sunrise', '--preset' => 'api', '--dev' => true,
            '--repository' => ['{"type":"path","url":"/src/skeleton"}', 'https://repo.example.com'], '--composer' => '/opt/composer.phar',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), self::text($tester));
        self::assertSame([
            PHP_BINARY, '/opt/composer.phar', 'create-project', 'crystoline/xpsr', $this->directory, 'dev-master', '--no-interaction',
            '--repository={"type":"path","url":"/src/skeleton"}', '--repository=https://repo.example.com', '--add-repository',
        ], $runner->commands[0]['command'], 'A .phar runs through PHP; --dev installs dev-master; repositories are kept in the project.');
        self::assertSame(
            [PHP_BINARY, "{$this->directory}/xpsr", 'setup', '--router=sunrise', '--preset=api', '--install-dependencies', '--no-interaction', '--composer=/opt/composer.phar'],
            $runner->commands[1]['command'],
        );

        $runner = new RecordingRunner();
        $this->execute($runner,['name' => $this->directory, '--release' => '^0.5']);
        self::assertSame(['/usr/bin/composer', 'create-project', 'crystoline/xpsr', $this->directory, '^0.5', '--no-interaction'], $runner->commands[0]['command']);
    }

    public function testGitCommitsTheProjectWhenAsked(): void
    {
        $runner = new RecordingRunner();
        $tester = $this->execute($runner,['name' => $this->directory, '--git' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['/usr/bin/git init -q', '/usr/bin/git add -A', '/usr/bin/git commit -q -m Create the project'], array_slice($runner->texts(), 2, 3));
        self::assertSame($this->directory, $runner->commands[3]['directory']);
        self::assertStringContainsString('Committed the project to a new git repository.', self::text($tester));

        $runner = new RecordingRunner(failures: ['commit' => 128]);
        $tester = $this->execute($runner,['name' => $this->directory, '--git' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'The project exists; a failed commit is a warning.');
        self::assertStringContainsString('git commit failed (exit 128), so the project is not committed.', self::text($tester));

        $runner = new RecordingRunner(programs: ['composer' => '/usr/bin/composer']);
        $tester = $this->execute($runner,['name' => $this->directory, '--git' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('git was not found on the PATH, so the project is not committed.', self::text($tester));
        self::assertCount(3, $runner->commands);
    }

    public function testAsksForWhatIsMissing(): void
    {
        $runner = new RecordingRunner();
        $tester = new CommandTester(new NewCommand($runner));
        $tester->setInputs(['shop', 'sunrise', 'api']);

        $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), self::text($tester));
        $directory = getcwd() . '/shop';
        self::assertSame("/usr/bin/composer create-project crystoline/xpsr {$directory} --no-interaction", $runner->texts()[0], 'A relative name is below the current directory.');
        self::assertStringContainsString('--router=sunrise --preset=api', $runner->texts()[1]);
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function refusals(): iterable
    {
        yield 'no name' => [[], Command::INVALID, 'A project directory is needed: xpsr new my-app'];
        yield 'unknown router' => [['name' => 'x', '--router' => 'symfony'], Command::INVALID, 'Unknown router "symfony". Routers: fastroute, sunrise.'];
        yield 'unknown preset' => [['name' => 'x', '--preset' => 'spa'], Command::INVALID, 'Unknown preset "spa". Presets: web, api.'];
        yield 'dev and release' => [['name' => 'x', '--dev' => true, '--release' => '0.5.0'], Command::INVALID, 'Pass --dev or --release, not both.'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('refusals')]
    public function testInvalidInputIsRefusedBeforeAnythingRuns(array $input, int $status, string $message): void
    {
        $runner = new RecordingRunner();
        $tester = $this->execute($runner,$input);

        self::assertSame($status, $tester->getStatusCode());
        self::assertStringContainsString($message, self::text($tester));
        self::assertSame([], $runner->commands);
    }

    public function testFailuresAreReported(): void
    {
        mkdir($this->directory);
        touch($this->directory . '/keep');
        $runner = new RecordingRunner();
        $tester = $this->execute($runner,['name' => $this->directory]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($this->directory . ' exists and is not empty.', self::text($tester));
        self::assertSame([], $runner->commands);
        unlink($this->directory . '/keep');

        $runner = new RecordingRunner(programs: []);
        $tester = $this->execute($runner,['name' => $this->directory]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Composer was not found on the PATH.', self::text($tester));
        self::assertStringContainsString('--composer=/path/to/composer.phar', self::text($tester));

        $runner = new RecordingRunner(failures: ['create-project' => 1]);
        $tester = $this->execute($runner,['name' => $this->directory]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("Composer could not create the project (exit 1); {$this->directory} may hold a partial project.", self::text($tester));
        self::assertCount(1, $runner->commands, 'Setup does not run.');

        $runner = new RecordingRunner(failures: ['setup' => 1]);
        $tester = $this->execute($runner,['name' => $this->directory, '--git' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The project was created, but its setup failed (exit 1)', self::text($tester));
        self::assertStringContainsString("run \"php xpsr setup\" in {$this->directory}.", self::text($tester));
        self::assertCount(2, $runner->commands, 'Nothing is committed.');
    }
}
