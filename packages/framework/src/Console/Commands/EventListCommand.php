<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Events\ListenerProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class EventListCommand extends Command
{
    public function __construct(private readonly ListenerProvider $listeners)
    {
        parent::__construct('event:list');
    }

    protected function configure(): void
    {
        $this->setDescription('List event listeners in the order they run.')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'Filter event classes containing this text.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption('event');
        $rows = [];
        foreach ($this->listeners->definitions() as $definition) {
            if (is_string($filter) && stripos($definition['event'], $filter) === false) {
                continue;
            }
            $rows[] = [
                'event' => $definition['event'],
                'priority' => $definition['priority'],
                'listener' => ListenerProvider::describe($definition['listener']),
            ];
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $table = array_map(static fn(array $row): array => [
            OutputFormatter::escape($row['event']),
            (string) $row['priority'],
            OutputFormatter::escape($row['listener']),
        ], $rows);
        (new Table($output))->setHeaders(['Event', 'Priority', 'Listener'])->setRows($table)->render();
        $output->writeln(count($rows) . ' listener(s). A listener also receives subclasses and implementations of its event.');

        return self::SUCCESS;
    }
}
