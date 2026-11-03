<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation;

use InvalidArgumentException;

/**
 * The "translation" configuration (config/translation.php), with app.locale and
 * app.fallback_locale from config/app.php:
 *
 *   locales          the locales the application serves; app.locale is always one (default: it alone)
 *   path             the directory of the message files, relative to the project (default lang)
 *   format           icu (plurals and selects, needs ext-intl) or simple (%name% placeholders);
 *                    default icu when ext-intl is loaded, otherwise simple
 *   cache            compile the catalogues into var/cache/translations (default: when
 *                    app.env is production)
 *   resolvers        where LocaleMiddleware reads the locale from, in order: route, session,
 *                    cookie, header (default all four)
 *   route_parameter  the route parameter (default locale)
 *   session_key      the session value (default locale)
 *   cookie           the cookie (default locale)
 *   domain           the domain of a key without one (default messages)
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class TranslationOptions
{
    public const RESOLVERS = ['route', 'session', 'cookie', 'header'];
    public const FORMATS = ['icu', 'simple'];

    /**
     * @param list<string> $locales
     * @param list<string> $resolvers
     */
    private function __construct(
        public readonly string $locale,
        public readonly string $fallback,
        public readonly array $locales,
        public readonly string $path,
        public readonly string $format,
        public readonly bool $cache,
        public readonly array $resolvers,
        public readonly string $routeParameter,
        public readonly string $sessionKey,
        public readonly string $cookie,
        public readonly string $domain,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function from(array $config, mixed $appLocale, mixed $appFallback, string $rootDir, bool $production, ?bool $intl = null): self
    {
        $known = ['locales', 'path', 'format', 'cache', 'resolvers', 'route_parameter', 'session_key', 'cookie', 'domain'];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option translation.%s. Known options: %s.', $key, implode(', ', $known)));
            }
        }
        $locale = self::locale($appLocale, 'app.locale') ?? 'en';
        $fallback = self::locale($appFallback, 'app.fallback_locale') ?? $locale;
        $locales = $config['locales'] ?? [$locale];
        if (!is_array($locales) || !array_is_list($locales) || $locales === []) {
            throw new InvalidArgumentException('translation.locales must be a list of locales, such as [\'en\', \'fr\'].');
        }
        $locales = array_values(array_unique(array_map(static fn(mixed $item): string => self::locale($item, 'translation.locales') ?? throw new InvalidArgumentException('translation.locales must be a list of locales, such as [\'en\', \'fr\'].'), $locales)));
        if (!in_array($locale, $locales, true)) {
            array_unshift($locales, $locale);
        }
        $path = $config['path'] ?? 'lang';
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('translation.path must be the directory of the message files.');
        }
        $absolute = str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:[/\\\\]#', $path) === 1;
        $format = $config['format'] ?? (($intl ?? extension_loaded('intl')) ? 'icu' : 'simple');
        if (!is_string($format) || !in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException('translation.format must be icu or simple.');
        }
        if ($format === 'icu' && !($intl ?? extension_loaded('intl'))) {
            throw new InvalidArgumentException('translation.format icu needs ext-intl; install it, or set the format to simple.');
        }
        $cache = $config['cache'] ?? $production;
        if (!is_bool($cache)) {
            throw new InvalidArgumentException('translation.cache must be true or false.');
        }
        $resolvers = $config['resolvers'] ?? self::RESOLVERS;
        if (!is_array($resolvers) || !array_is_list($resolvers) || $resolvers !== array_filter($resolvers, static fn(mixed $item): bool => is_string($item) && in_array($item, self::RESOLVERS, true))) {
            throw new InvalidArgumentException(sprintf('translation.resolvers must be a list of %s.', implode(', ', self::RESOLVERS)));
        }
        $names = [];
        foreach (['route_parameter' => 'locale', 'session_key' => 'locale', 'cookie' => 'locale', 'domain' => 'messages'] as $key => $default) {
            $value = $config[$key] ?? $default;
            if (!is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/', $value) !== 1) {
                throw new InvalidArgumentException("translation.{$key} must be a name of letters, digits, underscores, dots, and hyphens.");
            }
            $names[$key] = $value;
        }

        return new self(
            $locale,
            $fallback,
            $locales,
            $absolute ? $path : $rootDir . '/' . $path,
            $format,
            $cache,
            $resolvers,
            $names['route_parameter'],
            $names['session_key'],
            $names['cookie'],
            $names['domain'],
        );
    }

    /** The locale among the configured ones that a wanted locale maps to: exact, or by language ("fr-CA" to "fr", "fr" to "fr_FR"); null when none. */
    public function match(string $wanted): ?string
    {
        $wanted = str_replace('-', '_', trim($wanted));
        if ($wanted === '') {
            return null;
        }
        foreach ($this->locales as $locale) {
            if (strcasecmp($locale, $wanted) === 0) {
                return $locale;
            }
        }
        $language = strtolower((string) strtok($wanted, '_'));
        foreach ($this->locales as $locale) {
            if (strtolower($locale) === $language) {
                return $locale;
            }
        }
        foreach ($this->locales as $locale) {
            if (strtolower((string) strtok($locale, '_')) === $language) {
                return $locale;
            }
        }

        return null;
    }

    private static function locale(mixed $value, string $setting): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/\A[a-z]{2,3}([_-][A-Za-z0-9]{2,8})*\z/i', $value) !== 1) {
            throw new InvalidArgumentException("{$setting} must be a locale such as en, fr, or pt_BR.");
        }

        return str_replace('-', '_', $value);
    }
}
