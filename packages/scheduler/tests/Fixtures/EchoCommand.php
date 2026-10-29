<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Tests\Fixtures;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class EchoCommand extends Command
{
    public function __construct(private readonly Recorder $recorder)
    {
        parent::__construct('test:echo');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('word', InputArgument::REQUIRED)
            ->addOption('fail', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $word = (string) $input->getArgument('word');
        $this->recorder->done[] = "command {$word}";
        $output->writeln("Echo: {$word}");

        return $input->getOption('fail') ? 3 : self::SUCCESS;
    }
}
