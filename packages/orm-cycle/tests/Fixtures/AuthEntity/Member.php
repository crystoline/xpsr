<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\AuthEntity;

use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Orm\Cycle\Auth\PasswordUpgradableInterface;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(table: 'members')]
#[Index(columns: ['email'], unique: true, name: 'members_email_unique')]
class Member implements UserInterface, PasswordUpgradableInterface
{
    #[Column(type: 'primary')]
    public ?int $id = null;

    public function __construct(
        #[Column(type: 'string(255)')]
        public string $email,
        #[Column(type: 'string(255)', name: 'password_hash')]
        public string $passwordHash,
    ) {}

    public function authIdentifier(): int
    {
        return (int) $this->id;
    }

    public function authPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function upgradePasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
    }
}
