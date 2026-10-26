<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Application;
use Crystoline\Console\ConsoleKernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class AboutCommand extends Command
{
    public function __construct(private readonly Application $application)
    {
        parent::__construct('about');
    }

    protected function configure(): void
    {
        $this->setDescription('Show application and runtime information.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->application->getConfig();
        // Deliberately select fields; never dump the full configuration or environment.
        $information = [
            'framework' => 'XPSR',
            'version' => ConsoleKernel::VERSION,
            'application' => $config->get('app.name', 'XPSR'),
            'environment' => $config->get('app.env', 'production'),
            'debug' => (bool) $config->get('app.debug', false),
            'php' => PHP_VERSION,
            'router' => $config->get('routing.adapter', 'fastroute'),
            'root' => $this->application->getRootDir(),
        ];
        if ($input->getOption('json')) {
            $output->writeln(json_encode($information, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }
        $rows = [];
        foreach ($information as $key => $value) {
            $rows[] = [ucfirst($key), OutputFormatter::escape(is_bool($value) ? ($value ? 'enabled' : 'disabled') : (string) $value)];
        }
        (new Table($output))->setHeaders(['Setting', 'Value'])->setRows($rows)->render();
        return self::SUCCESS;
    }
}
