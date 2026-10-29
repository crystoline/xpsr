<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Auth;

/**
 * For user entities whose password hash sign-in may replace with a stronger one, when the hash
 * was made with an older algorithm or cost. DoctrineUserProvider sets it and flushes.
 */
interface PasswordUpgradableInterface
{
    public function upgradePasswordHash(string $hash): void;
}
