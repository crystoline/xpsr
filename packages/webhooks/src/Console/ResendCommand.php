<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Console;

use Crystoline\Xpsr\Webhooks\Deliveries;
use Crystoline\Xpsr\Webhooks\WebhooksInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Queues a delivery again: webhooks:resend <delivery id>.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ResendCommand extends Command
{
    public function __construct(private readonly WebhooksInterface $webhooks, private readonly Deliveries $deliveries)
    {
        parent::__construct('webhooks:resend');
    }

    protected function configure(): void
    {
        $this->setDescription('Send a webhook delivery again, with the same payload.')
            ->addArgument('id', InputArgument::REQUIRED, 'The delivery ID, from the delivery log or the X-Webhook-Id header.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');
        try {
            $delivery = $this->webhooks->resend($id);
        } catch (InvalidArgumentException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return self::FAILURE;
        }
        $delivery = $this->deliveries->find($delivery->id) ?? $delivery;
        $io->writeln(sprintf('Delivery %s (%s) queued again: %s after %d attempt(s).', $delivery->id, $delivery->event, $delivery->status, $delivery->attempts));

        return self::SUCCESS;
    }
}
