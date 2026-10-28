<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Migration;

use RuntimeException;

/** A migration could not be loaded, run, or rolled back. */
final class MigrationException extends RuntimeException {}
