<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Tests;

use Crystoline\Xpsr\Translation\TranslationOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslationOptionsTest extends TestCase
{
    public function testDefaultsAndValues(): void
    {
        $defaults = TranslationOptions::from([], 'en', 'en', '/srv/app', false, intl: false);
        self::assertSame('en', $defaults->locale);
        self::assertSame('en', $defaults->fallback);
        self::assertSame(['en'], $defaults->locales);
        self::assertSame('/srv/app/lang', $defaults->path);
        self::assertSame('simple', $defaults->format);
        self::assertFalse($defaults->cache);
        self::assertSame(['route', 'session', 'cookie', 'header'], $defaults->resolvers);
        self::assertSame('locale', $defaults->routeParameter);
        self::assertSame('locale', $defaults->sessionKey);
        self::assertSame('locale', $defaults->cookie);
        self::assertSame('messages', $defaults->domain);
        self::assertSame('icu', TranslationOptions::from([], 'en', 'en', '/srv/app', false, intl: true)->format);
        self::assertTrue(TranslationOptions::from([], 'en', 'en', '/srv/app', true, intl: false)->cache, 'Cached in production.');
        self::assertSame('en', TranslationOptions::from([], null, null, '/srv/app', false, intl: false)->locale, 'Without app.locale.');
        self::assertSame('fr', TranslationOptions::from([], 'fr', null, '/srv/app', false, intl: false)->fallback, 'The fallback defaults to the locale.');

        $options = TranslationOptions::from([
            'locales' => ['fr', 'en', 'pt-BR', 'fr'],
            'path' => '/srv/shared/lang',
            'format' => 'simple',
            'cache' => false,
            'resolvers' => ['cookie'],
            'route_parameter' => 'lang',
            'session_key' => 'user.locale',
            'cookie' => 'shop_lang',
            'domain' => 'ui',
        ], 'fr-FR', 'en', '/srv/app', true, intl: true);
        self::assertSame('fr_FR', $options->locale, 'Hyphens become underscores.');
        self::assertSame(['fr_FR', 'fr', 'en', 'pt_BR'], $options->locales, 'app.locale first when the list leaves it out; duplicates dropped.');
        self::assertSame('/srv/shared/lang', $options->path);
        self::assertSame('simple', $options->format);
        self::assertFalse($options->cache);
        self::assertSame(['cookie'], $options->resolvers);
        self::assertSame('lang', $options->routeParameter);
        self::assertSame('user.locale', $options->sessionKey);
        self::assertSame('shop_lang', $options->cookie);
        self::assertSame('ui', $options->domain);

        self::assertSame('fr', $options->match('fr'));
        self::assertSame('fr', $options->match('FR'));
        self::assertSame('pt_BR', $options->match('pt-br'));
        self::assertSame('pt_BR', $options->match('pt'), 'A language matches its regional locale.');
        self::assertSame('fr', $options->match('fr-CA'), 'A regional variant matches its language.');
        self::assertNull($options->match('de'));
        self::assertNull($options->match(''));
    }

    public function testAppLocaleIsAlwaysServed(): void
    {
        $options = TranslationOptions::from(['locales' => ['en', 'fr']], 'fr', 'en', '/srv/app', false, intl: false);
        self::assertSame('fr', $options->locale);
        self::assertSame(['en', 'fr'], $options->locales);
        $options = TranslationOptions::from(['locales' => ['en', 'fr']], 'de', 'en', '/srv/app', false, intl: false);
        self::assertSame(['de', 'en', 'fr'], $options->locales);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown option' => [['locale' => 'en'], 'Unknown option translation.locale. Known options: locales, path'];
        yield 'locales' => [['locales' => 'en,fr'], 'translation.locales must be a list of locales'];
        yield 'locales empty' => [['locales' => []], 'translation.locales must be a list of locales'];
        yield 'locale form' => [['locales' => ['en', 'english']], 'translation.locales must be a locale such as en, fr, or pt_BR.'];
        yield 'path' => [['path' => ''], 'translation.path must be the directory of the message files.'];
        yield 'format' => [['format' => 'gettext'], 'translation.format must be icu or simple.'];
        yield 'icu without intl' => [['format' => 'icu'], 'translation.format icu needs ext-intl; install it, or set the format to simple.'];
        yield 'cache' => [['cache' => 'yes'], 'translation.cache must be true or false.'];
        yield 'resolvers' => [['resolvers' => ['query']], 'translation.resolvers must be a list of route, session, cookie, header.'];
        yield 'cookie' => [['cookie' => 'my cookie'], 'translation.cookie must be a name of letters, digits, underscores, dots, and hyphens.'];
        yield 'domain' => [['domain' => ''], 'translation.domain must be a name of letters, digits, underscores, dots, and hyphens.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalid')]
    public function testInvalidOptionsNameTheSetting(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        TranslationOptions::from($config, 'en', 'en', '/srv/app', false, intl: false);
    }

    public function testAppLocaleMustBeALocale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('app.locale must be a locale such as en, fr, or pt_BR.');
        TranslationOptions::from([], 'English', 'en', '/srv/app', false, intl: false);
    }
}
