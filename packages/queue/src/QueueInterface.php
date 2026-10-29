<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

/**
 * Sends jobs to a transport, where a worker (php xpsr queue:work) runs them. On the sync
 * transport, a job runs at once, inside dispatch(), and its exceptions reach the caller.
 *
 *     $queue->dispatch(new SendWelcomeEmail($user->id));
 *     $queue->dispatch(new SendInvoice($invoice->id), delay: '10 minutes', queue: 'emails');
 */
interface QueueInterface
{
    /**
     * @param string|int|null $delay seconds, or a period such as "10 minutes", before the job may run
     * @param string|null $queue the transport from queue.transports; the job's #[OnQueue] or queue.default without one
     */
    public function dispatch(JobInterface $job, string|int|null $delay = null, ?string $queue = null): void;
}
