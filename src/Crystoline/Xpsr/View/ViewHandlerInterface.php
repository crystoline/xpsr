<?php

namespace Crystoline\Xpsr\View;
interface ViewHandlerInterface
{
    public function render(string $view, array $data = []): string;

}