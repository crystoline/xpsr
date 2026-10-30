<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use RuntimeException;
use Throwable;

/**
 * Some channels of a notification failed; the others were sent (or queued). failures() lists each
 * failed channel with its recipient and error; the first error is the previous exception.
 */
final class NotificationFailedException extends RuntimeException
{
    /**
     * @param class-string<NotificationInterface> $notification
     * @param non-empty-list<array{channel: string, to: NotifiableInterface, error: Throwable}> $failures
     * @param int $attempts how many channels were tried, the failed ones included
     */
    public function __construct(
        public readonly string $notification,
        private readonly array $failures,
        int $attempts,
    ) {
        $short = substr(strrchr('\\' . $notification, '\\') ?: $notification, 1);
        $lines = array_map(static function (array $failure): string {
            $key = $failure['to']->notifiableKey();

            return "{$failure['channel']} for " . ($key === null ? 'a recipient without a notifiable key' : (string) $key) . ": {$failure['error']->getMessage()}";
        }, $failures);
        parent::__construct(
            sprintf('%s failed on %d of %d %s: %s', $short, count($failures), $attempts, $attempts === 1 ? 'channel' : 'channels', implode('; ', $lines)),
            previous: $failures[0]['error'],
        );
    }

    /** @return non-empty-list<array{channel: string, to: NotifiableInterface, error: Throwable}> */
    public function failures(): array
    {
        return $this->failures;
    }
}
