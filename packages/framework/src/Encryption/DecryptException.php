<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Encryption;

use RuntimeException;

/** The data could not be decrypted: it is malformed, was changed, or is under another key. */
final class DecryptException extends RuntimeException {}
