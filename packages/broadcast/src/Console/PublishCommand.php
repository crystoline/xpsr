<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Console;

use Crystoline\Xpsr\Broadcast\Hub;
use Crystoline\Xpsr\Broadcast\HubException;
use Crystoline\Xpsr\Broadcast\Update;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Publishes one update to the hub now, from the shell, to try a hub or a page. */
final class PublishCommand extends Command
{
    public function __construct(private readonly Hub $hub)
    {
        parent::__construct('broadcast:publish');
    }

    protected function configure(): void
    {
        $this->setDescription('Publish an update to the Mercure hub.')
            ->addArgument('topic', InputArgument::REQUIRED, 'The topic, such as orders/42.')
            ->addArgument('data', InputArgument::OPTIONAL, 'The data, sent as given; JSON for a page that expects it.', '{}')
            ->addOption('private', null, InputOption::VALUE_NONE, 'Only for subscribers authorized for the topic.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The event name browsers listen for.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'The update\'s ID, instead of one from the hub.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $topic = $input->getArgument('topic');
        $data = $input->getArgument('data');
        $type = $input->getOption('type');
        $id = $input->getOption('id');
        try {
            $update = new Update(
                is_string($topic) ? $topic : '',
                is_string($data) ? $data : '{}',
                (bool) $input->getOption('private'),
                is_string($type) ? $type : null,
                is_string($id) ? $id : null,
            );
            $given = $this->hub->publish($update);
        } catch (HubException|InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
        $output->writeln(sprintf('<info>Published to %s%s.</info>', is_string($topic) ? $topic : '', $given === null ? '' : " (id {$given})"));

        return self::SUCCESS;
    }
}
