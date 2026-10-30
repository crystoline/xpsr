<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotifiableKey;

final class User implements NotifiableInterface
{
    public const TYPE = 'App\Models\User';

    public function __construct(
        public readonly int|string $id,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $slack = null,
    ) {}

    public function notificationAddress(string $channel): ?string
    {
        return match ($channel) {
            'mail' => $this->email,
            'sms' => $this->phone,
            'slack' => $this->slack,
            default => null,
        };
    }

    public function notifiableKey(): NotifiableKey
    {
        return new NotifiableKey(self::TYPE, $this->id);
    }
}
