<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health;

/**
 * What a check found: a status, a sentence for a person, and details for a machine (scalars,
 * lists, and maps of them). The time the check took is added by Health.
 */
final class Result
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly Status $status,
        public readonly string $message = '',
        public readonly array $details = [],
        public readonly float $milliseconds = 0.0,
    ) {}

    /** @param array<string, mixed> $details */
    public static function ok(string $message = '', array $details = []): self
    {
        return new self(Status::Ok, $message, $details);
    }

    /** Something to look at that does not stop the application serving. @param array<string, mixed> $details */
    public static function warning(string $message, array $details = []): self
    {
        return new self(Status::Warning, $message, $details);
    }

    /** The application cannot do its work. @param array<string, mixed> $details */
    public static function failing(string $message, array $details = []): self
    {
        return new self(Status::Failing, $message, $details);
    }

    /** @internal */
    public function timed(float $milliseconds): self
    {
        return new self($this->status, $this->message, $this->details, round($milliseconds, 2));
    }

    /**
     * @param bool $details with the message, details, and time, or the status alone
     * @return array<string, mixed>
     */
    public function toArray(bool $details): array
    {
        if (!$details) {
            return ['status' => $this->status->value];
        }

        return ['status' => $this->status->value, 'message' => $this->message, 'details' => $this->details, 'milliseconds' => $this->milliseconds];
    }
}
