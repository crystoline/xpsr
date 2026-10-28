<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\ServiceContainer;

use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends ContainerException implements NotFoundExceptionInterface {}
