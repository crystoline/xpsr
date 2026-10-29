<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

/**
 * A job: its properties are the data it needs, and its handle() method does the work. Services are
 * passed to handle() by type, so they are not stored with the job:
 *
 *     final class SendWelcomeEmail implements JobInterface
 *     {
 *         public function __construct(public readonly int $userId) {}
 *
 *         public function handle(Mailer $mailer, UserRepository $users): void
 *         {
 *             $mailer->send(...$users->find($this->userId));
 *         }
 *     }
 *
 * Keep properties to IDs and plain values: the job is serialized when it is queued, and runs
 * later, perhaps on another server.
 */
interface JobInterface {}
