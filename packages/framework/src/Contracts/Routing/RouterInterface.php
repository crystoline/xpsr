<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Contracts\Routing;

use Crystoline\Xpsr\Routing\RouteMatch;
use Psr\Http\Message\ServerRequestInterface;

interface RouterInterface
{
    public function match(ServerRequestInterface $request): RouteMatch;
}
