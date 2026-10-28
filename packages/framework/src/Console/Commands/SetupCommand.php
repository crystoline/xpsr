<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Commands;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\Setup\BootstrapCheck;
use Crystoline\Xpsr\Console\Setup\EnvironmentFile;
use Crystoline\Xpsr\Console\Setup\FileTransaction;
use Crystoline\Xpsr\Console\Setup\PackageInspectorInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Applies explicit router and preset choices, then verifies the application boots.
 *
 * Order: validate, plan file changes, install a missing adapter package, write files, run the
 * bootstrap check. Nothing is written if planning or Composer fails, and written files are
 * restored if the check fails.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SetupCommand extends Command
{
    /**
     * Router adapters that pass the shared routing contract suite. Others are configured manually.
     * Package names match RouteServiceRegistry::ADAPTERS.
     */
    public const ROUTERS = [
        'fastroute' => ['package' => 'crystoline/xpsr-router-fastroute', 'label' => 'FastRoute (default)'],
        'sunrise' => ['package' => 'crystoline/xpsr-router-sunrise', 'label' => 'Sunrise (migration adapter)'],
    ];

    /** Presets choose which skeleton route files load. */
    public const PRESETS = [
        'web' => ['files' => ['routes/api.php', 'routes/web.php'], 'label' => 'Web: HTML pages and JSON routes'],
        'api' => ['files' => ['routes/api.php'], 'label' => 'API: JSON routes only'],
    ];

    public function __construct(
        private readonly Application $application,
        private readonly PackageInspectorInterface $packages,
        private readonly BootstrapCheck $check,
    ) {
        parent::__construct('setup');
    }

    protected function configure(): void
    {
        $this->setDescription('Choose the router adapter and preset, then check that the application boots.')
            ->addOption('router', null, InputOption::VALUE_REQUIRED, 'Router adapter: ' . implode(' or ', array_keys(self::ROUTERS)) . '. Omit to keep the current one.')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Preset: ' . implode(' or ', array_keys(self::PRESETS)) . '. Omit to keep the current route files.')
            ->addOption('install-dependencies', null, InputOption::VALUE_NONE, 'Run "composer require" if the adapter package is not a production dependency yet.')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'Composer executable or .phar to use.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without changing anything.');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);
        $router = $this->currentRouter();
        if ($input->getOption('router') === null && isset(self::ROUTERS[$router])) {
            $input->setOption('router', $io->choice('Router adapter', array_map(static fn(array $r): string => $r['label'], self::ROUTERS), $router));
        }
        $preset = $this->currentPreset();
        if ($input->getOption('preset') === null && $preset !== null) {
            $input->setOption('preset', $io->choice('Preset', array_map(static fn(array $p): string => $p['label'], self::PRESETS), $preset));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();

        $router = $input->getOption('router') ?? $this->currentRouter();
        if ($input->getOption('router') !== null && (!is_string($router) || !isset(self::ROUTERS[$router]))) {
            $errors->error(sprintf(
                'Unknown router "%s". Choose %s. Custom adapters are configured manually; see docs/ROUTING.md.',
                is_string($router) ? $router : '',
                implode(' or ', array_keys(self::ROUTERS)),
            ));

            return self::INVALID;
        }
        $preset = $input->getOption('preset');
        if ($preset !== null && (!is_string($preset) || !isset(self::PRESETS[$preset]))) {
            $errors->error(sprintf('Unknown preset "%s". Choose %s.', is_string($preset) ? $preset : '', implode(' or ', array_keys(self::PRESETS))));

            return self::INVALID;
        }
        /** @var string $router */
        $managed = isset(self::ROUTERS[$router]);
        $files = $preset === null ? $this->currentFiles() : self::PRESETS[$preset]['files'];
        $root = $this->application->getRootDir();

        try {
            $changes = $this->plan($root, $managed ? $router : null, $files);
        } catch (RuntimeException $exception) {
            $errors->error($exception->getMessage() . ' No files were changed.');

            return self::FAILURE;
        }
        $requirement = null;
        if ($managed && !$this->packages->isRequired(self::ROUTERS[$router]['package'])) {
            // Adapters are released with the framework, so require the same release range.
            $requirement = self::ROUTERS[$router]['package'] . ':' . $this->packages->releaseConstraint();
        }

        $this->line($output, sprintf(
            'Router: %s. Preset: %s.',
            $managed ? $router : "custom ({$router}), unchanged",
            $preset ?? ($this->currentPreset() ?? 'custom route files, unchanged'),
        ));

        if ($input->getOption('dry-run')) {
            if ($requirement !== null) {
                $this->line($output, "Would run: composer require {$requirement}");
            }
            foreach ($changes as $change) {
                $this->line($output, 'Would ' . lcfirst($change['summary']));
            }
            if ($requirement === null && $changes === []) {
                $this->line($output, 'Nothing to change.');
            }

            return self::SUCCESS;
        }

        if ($requirement !== null) {
            $allowed = $input->getOption('install-dependencies')
                || ($input->isInteractive() && $io->confirm("Install {$requirement} with Composer now?", true));
            if (!$allowed) {
                $errors->error("The {$router} adapter needs {$requirement} as a production dependency. Run \"composer require {$requirement}\" or pass --install-dependencies. No files were changed.");

                return self::FAILURE;
            }
            try {
                $this->install($root, $requirement, $input->getOption('composer'), $output);
            } catch (RuntimeException $exception) {
                $errors->error($exception->getMessage() . ' No files were changed.');

                return self::FAILURE;
            }
            $this->line($output, "Installed {$requirement}.");
        }

        $transaction = new FileTransaction();
        try {
            foreach ($changes as $path => $change) {
                $transaction->write($root . '/' . $path, $change['contents']);
            }
            $result = $this->check->run($root);
            if ($result['adapter'] !== $router) {
                throw new RuntimeException("The application still uses the \"{$result['adapter']}\" router. ROUTER_ADAPTER may be set in the shell environment, or a configuration file may override routing.adapter.");
            }
            if ($result['files'] !== $files) {
                throw new RuntimeException('The application loads different route files than the preset. An environment-specific configuration file may override routing.files.');
            }
        } catch (RuntimeException $exception) {
            $failed = $transaction->rollback();
            $message = 'Setup check failed: ' . $exception->getMessage();
            if ($changes !== []) {
                $message .= $failed === [] ? ' The previous files were restored.' : ' Could not restore: ' . implode(', ', $failed) . '.';
            }
            if ($requirement !== null) {
                $message .= " {$requirement} remains installed; run \"composer remove " . self::ROUTERS[$router]['package'] . '" to undo that.';
            }
            $errors->error($message);

            return self::FAILURE;
        }

        foreach ($changes as $change) {
            $this->line($output, $change['summary']);
        }
        if ($changes === [] && $requirement === null) {
            $this->line($output, 'Already configured; nothing changed.');
        }
        $this->line($output, sprintf(
            'Bootstrap check passed: %d route(s); %d without placeholders matched by %s.',
            $result['routes'],
            $result['matched'],
            $result['router'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $files
     * @return array<string, array{contents: string, summary: string}> keyed by path relative to the root
     */
    private function plan(string $root, ?string $router, array $files): array
    {
        $changes = [];
        if ($router !== null) {
            $env = $root . '/.env';
            if (is_link($env)) {
                throw new RuntimeException(".env is a symbolic link; set ROUTER_ADAPTER={$router} in it manually.");
            }
            $current = is_file($env) ? (string) file_get_contents($env) : null;
            $example = $root . '/.env.example';
            $base = $current ?? (is_file($example) ? (string) file_get_contents($example) : '');
            $updated = EnvironmentFile::set($base, ['ROUTER_ADAPTER' => $router]);
            if ($updated !== $current) {
                $changes['.env'] = [
                    'contents' => $updated,
                    'summary' => $current === null
                        ? 'Create .env' . (is_file($example) ? ' from .env.example' : '') . " with ROUTER_ADAPTER={$router}."
                        : "Set ROUTER_ADAPTER={$router} in .env.",
                ];
            }
        }

        if ($this->application->getConfig()->get('routing.files') !== $files) {
            $path = $root . '/config/routing.php';
            $existing = is_file($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : null;
            $rendered = $this->renderRouting($files);
            $known = array_map(fn(array $preset): string => $this->renderRouting($preset['files']), self::PRESETS);
            if (is_link($path) || ($existing !== null && !in_array($existing, $known, true))) {
                throw new RuntimeException("config/routing.php has been customized, so setup will not rewrite it. Set 'files' => " . self::phpList($files) . ' there.');
            }
            if ($existing !== $rendered) {
                $changes['config/routing.php'] = [
                    'contents' => $rendered,
                    'summary' => 'Write config/routing.php to load ' . implode(' and ', $files) . '.',
                ];
            }
        }

        return $changes;
    }

    private function install(string $root, string $requirement, mixed $composer, OutputInterface $output): void
    {
        // --update-with-dependencies lets Composer change the adapter's own dependencies, for example
        // move psr/http-message to the major version Sunrise needs. The application's direct
        // requirements stay locked.
        $command = [...$this->composerCommand($composer), 'require', $requirement, '--update-with-dependencies', '--no-interaction', '--working-dir=' . $root];
        $process = new Process($command, $root, BootstrapCheck::environment(), timeout: null);
        // Composer's progress goes to stderr so stdout stays a short summary.
        $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $process->run(static function (string $type, string $buffer) use ($stream): void {
            $stream->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });
        if (!$process->isSuccessful()) {
            throw new RuntimeException("Composer could not install {$requirement} (exit status {$process->getExitCode()}).");
        }
    }

    /** @return non-empty-list<string> */
    private function composerCommand(mixed $option): array
    {
        $finder = new ExecutableFinder();
        if (is_string($option) && $option !== '') {
            $binary = is_file($option) ? $option : $finder->find($option);
        } else {
            $fromEnvironment = getenv('COMPOSER_BINARY');
            $binary = is_string($fromEnvironment) && is_file($fromEnvironment) ? $fromEnvironment : $finder->find('composer');
        }
        if ($binary === null || $binary === '') {
            throw new RuntimeException('Composer was not found. Install it or pass --composer=/path/to/composer.');
        }

        return preg_match('/\.(?:phar|php)\z/i', $binary) ? [PHP_BINARY, $binary] : [$binary];
    }

    private function currentRouter(): string
    {
        $adapter = $this->application->getConfig()->get('routing.adapter', 'fastroute');

        return is_string($adapter) ? $adapter : get_debug_type($adapter);
    }

    /** @return list<string> */
    private function currentFiles(): array
    {
        $files = $this->application->getConfig()->get('routing.files', []);

        return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
    }

    private function currentPreset(): ?string
    {
        foreach (self::PRESETS as $name => $preset) {
            if ($this->application->getConfig()->get('routing.files') === $preset['files']) {
                return $name;
            }
        }

        return null;
    }

    /** @param list<string> $files */
    private function renderRouting(array $files): string
    {
        $template = (string) file_get_contents(dirname(__DIR__) . '/Resources/stubs/config-routing.stub');

        return str_replace('{{ files }}', self::phpList($files), $template);
    }

    /** @param list<string> $files */
    private static function phpList(array $files): string
    {
        return '[' . implode(', ', array_map(static fn(string $file): string => var_export($file, true), $files)) . ']';
    }

    private function line(OutputInterface $output, string $message): void
    {
        $output->writeln(OutputFormatter::escape($message));
    }
}
