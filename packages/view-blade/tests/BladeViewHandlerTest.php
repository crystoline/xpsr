<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Blade\Tests;

use Crystoline\Xpsr\Testing\ViewHandlerContractTestCase;
use Crystoline\Xpsr\View\Blade\BladeViewHandler;
use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;

final class BladeViewHandlerTest extends ViewHandlerContractTestCase
{
    protected function createHandler(string $directory, string $cache): ViewHandlerInterface&ViewFunctionsInterface
    {
        return new BladeViewHandler($directory, false, $cache);
    }

    protected function extension(): string
    {
        return 'blade.php';
    }

    protected function templates(): array
    {
        return [
            'greeting' => 'Hello, {{ $name }}!',
            'html' => '{{ $xpsr->badge() }}',
            'text' => '{{ $xpsr->shout($text) }}',
            'arguments' => '{{ $xpsr->add(2, 3) }}',
        ];
    }
}
