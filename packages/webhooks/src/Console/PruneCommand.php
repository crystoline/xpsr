<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Console;

use Crystoline\Xpsr\Webhooks\Deliveries;
use DateInterval;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes old delivered and failed deliveries from the log: webhooks:prune --days=30.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class PruneCommand extends Command
{
    public function __construct(private readonly Deliveries $deliveries, private readonly ClockInterface $clock)
    {
        parent::__construct('webhooks:prune');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove delivered and failed webhook deliveries older than a number of days.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep deliveries newer than this many days.', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $input->getOption('days');
        if (!is_string($days) || !ctype_digit($days) || (int) $days < 1) {
            $io->getErrorStyle()->error('--days must be a whole number of days, one or more.');

            return self::INVALID;
        }
        $count = $this->deliveries->prune($this->clock->now()->sub(new DateInterval("P{$days}D")));
        $io->writeln(sprintf('Removed %d webhook deliver%s older than %d day(s).', $count, $count === 1 ? 'y' : 'ies', (int) $days));

        return self::SUCCESS;
    }
}
