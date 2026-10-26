<?php

namespace Crystoline\View;

interface ViewHandlerInterface
{
    public function render(string $view, array $data = []): string;

}
