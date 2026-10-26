<?php

declare(strict_types=1);

namespace Crystoline\Contracts\Routing;

use Crystoline\Routing\RouteMatch;
use Psr\Http\Message\ServerRequestInterface;

interface RouterInterface
{
    public function match(ServerRequestInterface $request): RouteMatch;
}
