<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Contracts\Container;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/** Registration boundary. Runtime consumers should depend on PSR-11 alone. */
interface ContainerInterface extends PsrContainerInterface, ContainerBuilderInterface {}
