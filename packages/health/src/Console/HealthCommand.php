<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Console;

use Crystoline\Xpsr\Health\Health;
use Crystoline\Xpsr\Health\Status;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Runs the checks now and prints them; exits 1 when one fails (or warns, with --strict). */
final class HealthCommand extends Command
{
    public function __construct(private readonly Health $health)
    {
        parent::__construct('health');
    }

    protected function configure(): void
    {
        $this->setDescription('Run the health checks.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON, with details.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit 1 on a warning too.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->health->check(fresh: true);
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report->toArray(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $rows = [];
            foreach ($report->results as $name => $result) {
                $rows[] = [$name, self::label($result->status), $result->message, sprintf('%.1f', $result->milliseconds)];
            }
            $io = new SymfonyStyle($input, $output);
            $io->table(['Check', 'Status', 'Message', 'ms'], $rows);
            $io->writeln('Status: ' . self::label($report->status()));
        }
        $status = $report->status();
        if ($status === Status::Failing || ($status === Status::Warning && $input->getOption('strict'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private static function label(Status $status): string
    {
        return match ($status) {
            Status::Ok => '<info>ok</info>',
            Status::Warning => '<comment>warning</comment>',
            Status::Failing => '<error>failing</error>',
        };
    }
}
