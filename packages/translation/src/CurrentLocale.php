<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation;

use Closure;

/**
 * The locale of the current request, command, or job: app.locale until LocaleMiddleware or
 * code sets another. The translator follows it.
 */
final class CurrentLocale
{
    private ?string $locale = null;

    /** @var list<Closure(string): void> */
    private array $listeners = [];

    public function __construct(private readonly string $default) {}

    public function get(): string
    {
        return $this->locale ?? $this->default;
    }

    public function set(string $locale): void
    {
        $this->locale = $locale;
        foreach ($this->listeners as $listener) {
            $listener($locale);
        }
    }

    /** Back to app.locale. */
    public function reset(): void
    {
        $this->set($this->default);
        $this->locale = null;
    }

    public function default(): string
    {
        return $this->default;
    }

    /** @internal Called with each new locale; the translator keeps in step this way. @param Closure(string): void $listener */
    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }
}
