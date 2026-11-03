<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health;

/** The outcome of a check, or of a whole report: the worst of its checks. */
enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failing = 'failing';

    /** The worse of the two. */
    public function worse(Status $other): Status
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    private function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Failing => 2,
        };
    }
}
