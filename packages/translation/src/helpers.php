<?php

declare(strict_types=1);

use Crystoline\Xpsr\Translation\CurrentLocale;
use Crystoline\Xpsr\Translation\Translator;

if (!function_exists('trans')) {
    /**
     * Translates a key in the current locale: trans('messages.welcome', ['name' => 'Ada']).
     *
     * @param array<string, mixed> $parameters
     */
    function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        return app()->get(Translator::class)->trans($key, $parameters, $locale);
    }
}

if (!function_exists('locale')) {
    /** The locale of the current request, command, or job. */
    function locale(): string
    {
        return app()->get(CurrentLocale::class)->get();
    }
}
