<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Console;

use Crystoline\Xpsr\Queue\Failed\FailedJobListener;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Runs queued jobs from one or more transports until stopped. SIGTERM and SIGINT stop it after
 * the current job, and so does php xpsr queue:restart (with crystoline/xpsr-cache).
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class QueueWorkCommand extends Command implements SignalableCommandInterface
{
    private ?Worker $worker = null;

    /** @param list<EventSubscriberInterface> $subscribers more listeners on the worker, such as the release of #[Unique] locks */
    public function __construct(
        private readonly Transports $transports,
        private readonly MessageBusInterface $bus,
        private readonly string $default,
        private readonly ?FailedJobStore $failed = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $subscribers = [],
    ) {
        parent::__construct('queue:work');
    }

    protected function configure(): void
    {
        $this->setDescription('Run queued jobs.')
            ->addArgument('transports', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Transports to take jobs from, in order of priority. Default: queue.default.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many jobs.')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds.')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop when memory use passes this, such as 128M.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when there are no jobs.', '1')
            ->addOption('stop-when-empty', null, InputOption::VALUE_NONE, 'Stop once the transports have no jobs left.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $names = $input->getArgument('transports');
        $names = is_array($names) && $names !== [] ? array_values(array_map(strval(...), $names)) : [$this->default];
        $receivers = [];
        foreach ($names as $name) {
            if (!$this->transports->has($name)) {
                $errors->error("There is no transport \"{$name}\" in queue.transports. Configured transports: " . implode(', ', $this->transports->names()) . '.');

                return self::INVALID;
            }
            if ($this->transports->isSync($name)) {
                $errors->error("The transport \"{$name}\" is sync://: its jobs run when they are dispatched, so there is nothing to work on. Give it another DSN, such as database://default.");

                return self::INVALID;
            }
            $receivers[$name] = $this->transports->get($name);
        }
        $limits = $this->limits($input, $errors);
        if ($limits === null) {
            return self::INVALID;
        }
        [$jobs, $seconds, $memory, $sleep] = $limits;

        $events = new EventDispatcher();
        $events->addSubscriber(new SendFailedMessageForRetryListener($this->transports, $this->transports->retryStrategies(), $this->logger, $events));
        $events->addSubscriber(new FailedJobListener($this->failed, $this->logger));
        foreach ($this->subscribers as $subscriber) {
            $events->addSubscriber($subscriber);
        }
        if ($jobs !== null) {
            $events->addSubscriber(new StopWorkerOnMessageLimitListener($jobs, $this->logger));
        }
        if ($seconds !== null) {
            $events->addSubscriber(new StopWorkerOnTimeLimitListener($seconds, $this->logger));
        }
        if ($memory !== null) {
            $events->addSubscriber(new StopWorkerOnMemoryLimitListener($memory, $this->logger));
        }
        if ($this->cache !== null) {
            $events->addSubscriber(new StopWorkerOnRestartSignalListener($this->cache, $this->logger));
        }
        if ($input->getOption('stop-when-empty')) {
            $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
                if ($event->isWorkerIdle()) {
                    $event->getWorker()->stop();
                }
            });
        }
        $events->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $event) use ($output): void {
            $output->writeln(OutputFormatter::escape(sprintf('Done %s (%s)', $event->getEnvelope()->getMessage()::class, $event->getReceiverName())));
        });
        $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use ($output): void {
            $output->writeln(OutputFormatter::escape(sprintf(
                'Failed %s (%s): %s%s',
                $event->getEnvelope()->getMessage()::class,
                $event->getReceiverName(),
                FailedJobListener::unwrap($event->getThrowable())->getMessage(),
                $event->willRetry() ? ' Retrying later.' : ' No retries left.',
            )));
        }, -200);

        $output->writeln(OutputFormatter::escape('Working on ' . implode(', ', $names) . '. Stop with Ctrl+C.'));
        $this->worker = new Worker($receivers, $this->bus, $events, $this->logger);
        $this->worker->run(['sleep' => (int) round($sleep * 1_000_000)]);
        $this->worker = null;

        return self::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return defined('SIGTERM') ? [SIGTERM, SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // Finish the current job, then stop.
        $this->worker?->stop();

        return false;
    }

    /** @return array{int|null, int|null, int|null, float}|null job limit, seconds, bytes, sleep */
    private function limits(InputInterface $input, SymfonyStyle $errors): ?array
    {
        $limit = $input->getOption('limit');
        $time = $input->getOption('time-limit');
        $memory = $input->getOption('memory-limit');
        $sleep = $input->getOption('sleep');
        if (($limit !== null && !ctype_digit((string) $limit)) || ($time !== null && !ctype_digit((string) $time)) || !is_numeric($sleep) || (float) $sleep < 0) {
            $errors->error('--limit and --time-limit must be whole numbers, and --sleep a number of seconds.');

            return null;
        }
        $bytes = null;
        if ($memory !== null) {
            if (!preg_match('/\A(\d+)([KMG]?)\z/i', (string) $memory, $match)) {
                $errors->error('--memory-limit must be a size such as 128M or 1G.');

                return null;
            }
            $bytes = (int) $match[1] * (1024 ** (int) strpos(' KMG', strtoupper($match[2] ?: ' ')));
        }

        return [$limit === null ? null : (int) $limit, $time === null ? null : (int) $time, $bytes, (float) $sleep];
    }
}
