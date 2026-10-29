<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Testing\ViewHandlerContractTestCase;
use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandler;
use Crystoline\Xpsr\View\ViewHandlerInterface;

final class TwigViewHandlerTest extends ViewHandlerContractTestCase
{
    protected function createHandler(string $directory, string $cache): ViewHandlerInterface&ViewFunctionsInterface
    {
        return new ViewHandler($directory, false, $cache);
    }

    protected function extension(): string
    {
        return 'twig';
    }

    protected function templates(): array
    {
        return [
            'greeting' => 'Hello, {{ name }}!',
            'html' => '{{ badge() }}',
            'text' => '{{ shout(text) }}',
            'arguments' => '{{ add(2, 3) }}',
        ];
    }
}
