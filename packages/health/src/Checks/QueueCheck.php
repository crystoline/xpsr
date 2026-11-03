<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Checks;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * Asks each transport for its waiting jobs (where it can count them), which fails when the
 * transport cannot be reached, and warns when the failed jobs table holds more than
 * health.failed_jobs jobs.
 */
final class QueueCheck implements CheckInterface
{
    public function __construct(
        private readonly Transports $transports,
        private readonly ?FailedJobStore $failed,
        private readonly int $failedJobs,
    ) {}

    public function name(): string
    {
        return 'queue';
    }

    public function check(): Result
    {
        $details = [];
        foreach ($this->transports->names() as $name) {
            if ($this->transports->isSync($name)) {
                $details[$name] = ['sync' => true];
                continue;
            }
            $transport = $this->transports->get($name);
            $details[$name] = ['waiting' => $transport instanceof MessageCountAwareInterface ? $transport->getMessageCount() : null];
        }
        if ($this->failed !== null) {
            $failed = count($this->failed->all());
            $details['failed'] = $failed;
            if ($failed > $this->failedJobs) {
                return Result::warning(sprintf('%d failed job%s', $failed, $failed === 1 ? '' : 's'), $details);
            }
        }

        return Result::ok('Transports answer', $details);
    }
}
