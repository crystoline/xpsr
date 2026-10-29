<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Encryption;

use RuntimeException;

/** Encryption was used, but app.key (APP_KEY) is not set. */
final class MissingKeyException extends RuntimeException {}
