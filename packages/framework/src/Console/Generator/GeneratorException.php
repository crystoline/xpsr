<?php

declare(strict_types=1);

namespace Crystoline\Console\Generator;

use RuntimeException;

/** A generator could not write its file, for example because the file already exists. */
final class GeneratorException extends RuntimeException {}
