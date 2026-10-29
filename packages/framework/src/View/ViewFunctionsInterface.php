<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View;

/**
 * A view handler that templates can call PHP functions from. The view provider adds
 * csrf_token(), csrf_field(), session(), auth_user(), and can() through it, whatever the engine.
 */
interface ViewFunctionsInterface
{
    /**
     * Makes $function callable from templates as $name. Add functions before the first render.
     * With $html, the result is printed without escaping, so it must be safe HTML; otherwise the
     * engine escapes it as it escapes any value.
     */
    public function addFunction(string $name, callable $function, bool $html = false): void;
}
