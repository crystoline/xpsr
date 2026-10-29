<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures;

use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;

/** A custom engine: prints the view name and the functions it was given. */
final class EchoViewHandler implements ViewHandlerInterface, ViewFunctionsInterface
{
    /** @var array<string, bool> function name => html */
    public array $functions = [];

    public function render(string $view, array $data = []): string
    {
        return $view . ': ' . implode(', ', array_keys($this->functions));
    }

    public function addFunction(string $name, callable $function, bool $html = false): void
    {
        $this->functions[$name] = $html;
    }
}
