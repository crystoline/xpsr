<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Crystoline\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GreetCommand extends Command
{
    public function __construct(private readonly Configuration $configuration)
    {
        parent::__construct('app:greet');
    }

    protected function configure(): void
    {
        $this->setDescription('Example application command with constructor injection.')
            ->addArgument('name', InputArgument::OPTIONAL, 'Who to greet.', 'developer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Hello, %s! Welcome to %s.', $input->getArgument('name'), $this->configuration->get('app.name', 'XPSR')), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }
}
