<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Blade;

use ArrayObject;
use BadMethodCallException;
use Illuminate\Support\HtmlString;
use Stringable;

/**
 * The template functions, shared with every Blade view as $xpsr:
 *
 *     {{ $xpsr->csrf_field() }}   {{ $xpsr->session('status') }}   @if ($xpsr->can('update', $task))
 *
 * Results of html functions are HtmlString, which {{ }} prints as it is; other results are
 * escaped by {{ }} as usual. The object has no methods of its own, so any function name works.
 */
final class BladeFunctions
{
    /** @param ArrayObject<string, array{callable, bool}> $functions name => [function, html], filled by BladeViewHandler */
    public function __construct(private readonly ArrayObject $functions) {}

    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        [$function, $html] = $this->functions[$name]
            ?? throw new BadMethodCallException("There is no template function {$name}(). Functions: " . implode(', ', array_keys($this->functions->getArrayCopy())) . '.');
        $result = $function(...$arguments);
        if (!$html) {
            return $result;
        }

        return new HtmlString(is_scalar($result) || $result instanceof Stringable ? (string) $result : '');
    }
}
