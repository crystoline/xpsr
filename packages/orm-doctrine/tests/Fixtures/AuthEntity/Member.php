<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\AuthEntity;

use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Orm\Doctrine\Auth\PasswordUpgradableInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'members')]
class Member implements UserInterface, PasswordUpgradableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255, unique: true)]
        public string $email,
        #[ORM\Column(length: 255)]
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
