<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Console;

use Crystoline\Xpsr\Audit\AuditLog;
use DateInterval;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes old entries: audit:prune --days=365.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class PruneCommand extends Command
{
    public function __construct(private readonly AuditLog $log, private readonly ClockInterface $clock)
    {
        parent::__construct('audit:prune');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove audit log entries older than a number of days.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep entries newer than this many days.', '365');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $input->getOption('days');
        if (!is_string($days) || !ctype_digit($days) || (int) $days < 1) {
            $io->getErrorStyle()->error('--days must be a whole number of days, one or more.');

            return self::INVALID;
        }
        $count = $this->log->prune($this->clock->now()->sub(new DateInterval("P{$days}D")));
        $io->writeln(sprintf('Removed %d audit log entr%s older than %d day(s).', $count, $count === 1 ? 'y' : 'ies', (int) $days));

        return self::SUCCESS;
    }
}
