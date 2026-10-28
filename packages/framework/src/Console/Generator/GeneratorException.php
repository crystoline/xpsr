<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Generator;

use RuntimeException;

/**
 * A generator could not write its file, for example because the file already exists.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class GeneratorException extends RuntimeException {}
