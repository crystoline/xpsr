<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Installer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a project: "composer create-project crystoline/xpsr", then the new project's own
 * "php xpsr setup" with the router and preset, and a git commit if asked. The installer knows
 * nothing about the project's files; the project's commands do, so one installed version works
 * with every XPSR release.
 */
final class NewCommand extends Command
{
    private const ROUTERS = ['fastroute', 'sunrise'];
    private const PRESETS = ['web', 'api'];

    public function __construct(private readonly RunnerInterface $runner)
    {
        parent::__construct('new');
    }

    protected function configure(): void
    {
        $this->setDescription('Create an XPSR project.')
            ->addArgument('name', InputArgument::OPTIONAL, 'The directory to create, such as my-app.')
            ->addOption('router', null, InputOption::VALUE_REQUIRED, 'Router adapter: fastroute (the default) or sunrise.')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Route files: web (the default: routes/web.php and routes/api.php) or api (routes/api.php only).')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialise a git repository and commit the new project.')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'The development line (dev-master) instead of the latest release.')
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'A release or constraint to install, such as 0.5.1 or ^0.5.')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A Composer repository (JSON or URL) to install from, kept in the project. For unreleased packages.')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'The Composer executable or .phar to use, when it is not on the PATH.');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);
        if (($input->getArgument('name') ?? '') === '') {
            $input->setArgument('name', $io->ask('Project directory', 'my-app'));
        }
        if ($input->getOption('router') === null) {
            $input->setOption('router', $io->choice('Router', self::ROUTERS, 'fastroute'));
        }
        if ($input->getOption('preset') === null) {
            $input->setOption('preset', $io->choice('Preset', self::PRESETS, 'web'));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');
        $router = $input->getOption('router') ?? 'fastroute';
        $preset = $input->getOption('preset') ?? 'web';
        $release = $input->getOption('release');
        if ($name === '') {
            $io->error('A project directory is needed: xpsr new my-app');

            return self::INVALID;
        }
        if (!in_array($router, self::ROUTERS, true)) {
            $io->error(sprintf('Unknown router "%s". Routers: %s.', $router, implode(', ', self::ROUTERS)));

            return self::INVALID;
        }
        if (!in_array($preset, self::PRESETS, true)) {
            $io->error(sprintf('Unknown preset "%s". Presets: %s.', $preset, implode(', ', self::PRESETS)));

            return self::INVALID;
        }
        if ($input->getOption('dev') && $release !== null) {
            $io->error('Pass --dev or --release, not both.');

            return self::INVALID;
        }
        $directory = self::absolute($name);
        if (is_dir($directory) && count((array) scandir($directory)) > 2) {
            $io->error("{$directory} exists and is not empty.");

            return self::FAILURE;
        }
        $composer = $input->getOption('composer') ?? $this->runner->find('composer');
        if (!is_string($composer) || $composer === '') {
            $io->error('Composer was not found on the PATH. Install it from https://getcomposer.org, or pass --composer=/path/to/composer.phar.');

            return self::FAILURE;
        }
        $composerCommand = preg_match('/\.(?:phar|php)\z/', $composer) ? [PHP_BINARY, $composer] : [$composer];

        $io->writeln("Creating {$directory} with Composer.");
        $repositories = array_map(static fn(string $repository): string => '--repository=' . $repository, $input->getOption('repository'));
        $version = $input->getOption('dev') ? ['dev-master'] : ($release !== null ? [(string) $release] : []);
        $exit = $this->runner->run([
            ...$composerCommand, 'create-project', 'crystoline/xpsr', $directory, ...$version, '--no-interaction',
            ...$repositories, ...($repositories === [] ? [] : ['--add-repository']),
        ]);
        if ($exit !== 0) {
            $io->error("Composer could not create the project (exit {$exit}); {$directory} may hold a partial project.");

            return self::FAILURE;
        }

        $io->writeln("Setting up: router {$router}, preset {$preset}.");
        $exit = $this->runner->run([
            PHP_BINARY, $directory . '/xpsr', 'setup', "--router={$router}", "--preset={$preset}", '--install-dependencies', '--no-interaction', "--composer={$composer}",
        ], $directory);
        if ($exit !== 0) {
            $io->error("The project was created, but its setup failed (exit {$exit}): see the output above, then run \"php xpsr setup\" in {$directory}.");

            return self::FAILURE;
        }

        if ($input->getOption('git')) {
            $this->commit($directory, $io);
        }
        $version = trim((string) $this->runner->capture([PHP_BINARY, $directory . '/xpsr', '--version'], $directory));
        $io->success(sprintf('Created %s in %s%s.', basename($directory), $directory, $version === '' ? '' : " ({$version})"));
        $io->writeln(['Next:', "    cd {$name}", '    php xpsr serve']);

        return self::SUCCESS;
    }

    private function commit(string $directory, SymfonyStyle $io): void
    {
        $git = $this->runner->find('git');
        if ($git === null) {
            $io->warning('git was not found on the PATH, so the project is not committed.');

            return;
        }
        foreach ([['init', '-q'], ['add', '-A'], ['commit', '-q', '-m', 'Create the project']] as $arguments) {
            $exit = $this->runner->run([$git, ...$arguments], $directory);
            if ($exit !== 0) {
                $io->warning(sprintf('git %s failed (exit %d), so the project is not committed. Set git\'s user.name and user.email, then commit it yourself.', $arguments[0], $exit));

                return;
            }
        }
        $io->writeln('Committed the project to a new git repository.');
    }

    private static function absolute(string $name): string
    {
        $absolute = preg_match('#\A(?:/|[A-Za-z]:[/\\\\]|\\\\\\\\)#', $name) === 1;

        return rtrim($absolute ? $name : getcwd() . '/' . $name, '/\\');
    }
}
