<?php

declare(strict_types=1);

namespace Crystoline\Xpsr;

use RuntimeException;

/**
 * var/cache/config.php exists but cannot be used: it was built for another APP_ENV, or from files
 * that have changed since. The application reads the configuration files instead and reports the
 * reason; config:cache again, or config:clear, ends it.
 */
final class StaleConfigurationCacheException extends RuntimeException {}
