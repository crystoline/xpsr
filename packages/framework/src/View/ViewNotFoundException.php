<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View;

use RuntimeException;
use Throwable;

/** render() was asked for a view that has no template file. */
class ViewNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $view, string $file, ?Throwable $previous = null)
    {
        parent::__construct("The view \"{$view}\" has no template: {$file} does not exist.", 0, $previous);
    }
}
