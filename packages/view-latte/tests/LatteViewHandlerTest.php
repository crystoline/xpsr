<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Latte\Tests;

use Crystoline\Xpsr\Testing\ViewHandlerContractTestCase;
use Crystoline\Xpsr\View\Latte\LatteViewHandler;
use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;

final class LatteViewHandlerTest extends ViewHandlerContractTestCase
{
    protected function createHandler(string $directory, string $cache): ViewHandlerInterface&ViewFunctionsInterface
    {
        return new LatteViewHandler($directory, false, $cache);
    }

    protected function extension(): string
    {
        return 'latte';
    }

    protected function templates(): array
    {
        return [
            'greeting' => 'Hello, {$name}!',
            'html' => '{badge()}',
            'text' => '{shout($text)}',
            'arguments' => '{add(2, 3)}',
        ];
    }
}
