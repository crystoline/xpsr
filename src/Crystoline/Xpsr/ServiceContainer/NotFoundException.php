<?php

namespace Crystoline\Xpsr\ServiceContainer;

use Psr\Container\NotFoundExceptionInterface;
use Exception;

/**
 * Class not found
 */
class NotFoundException extends Exception implements NotFoundExceptionInterface {}