<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Plates\Tests;

use Crystoline\Xpsr\Testing\ViewHandlerContractTestCase;
use Crystoline\Xpsr\View\Plates\PlatesViewHandler;
use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;

final class PlatesViewHandlerTest extends ViewHandlerContractTestCase
{
    protected function createHandler(string $directory, string $cache): ViewHandlerInterface&ViewFunctionsInterface
    {
        return new PlatesViewHandler($directory, false, $cache);
    }

    protected function extension(): string
    {
        return 'php';
    }

    protected function templates(): array
    {
        return [
            'greeting' => 'Hello, <?= $this->e($name) ?>!',
            'html' => '<?= $this->badge() ?>',
            'text' => '<?= $this->e($this->shout($text)) ?>',
            'arguments' => '<?= $this->add(2, 3) ?>',
        ];
    }
}
