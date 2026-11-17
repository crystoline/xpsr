<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

use RuntimeException;

/** Seeding was refused, or a seeder failed; in both cases nothing was changed. */
final class SeedException extends RuntimeException {}
