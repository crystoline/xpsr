<?php

declare(strict_types=1);

namespace Crystoline\Contracts\Routing;

interface UrlGeneratorInterface
{
    public function generate(string $name, array $parameters = [], array $query = []): string;
}
