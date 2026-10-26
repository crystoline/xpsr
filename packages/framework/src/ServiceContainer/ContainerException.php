<?php

declare(strict_types=1);

namespace Crystoline\ServiceContainer;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class ContainerException extends RuntimeException implements ContainerExceptionInterface {}
