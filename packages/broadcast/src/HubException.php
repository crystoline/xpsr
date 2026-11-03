<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use RuntimeException;

/** The hub could not be reached, or refused an update: the message says which, with its answer. */
final class HubException extends RuntimeException {}
