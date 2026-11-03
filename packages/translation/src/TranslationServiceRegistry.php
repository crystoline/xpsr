<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Translation\Console\MakeLangCommand;
use Crystoline\Xpsr\Translation\Console\MissingCommand;
use Crystoline\Xpsr\Translation\Http\LocaleMiddleware;
use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator as SymfonyTranslator;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers translations, configured in config/translation.php (see TranslationOptions) with
 * app.locale and app.fallback_locale:
 *
 *   Crystoline\Xpsr\Translation\Translator              trans(), has(), locale(). Shared.
 *   Crystoline\Xpsr\Translation\CurrentLocale           the locale of the current work. Shared.
 *   Crystoline\Xpsr\Translation\Http\LocaleMiddleware   sets it from the request.
 *   Symfony\Contracts\Translation\TranslatorInterface   Symfony's translator underneath. Shared.
 *
 * the commands translation:missing and make:lang, the trans() and locale() functions in every
 * view engine, and Symfony's own translations of the validation messages when
 * crystoline/xpsr-validation is installed (its validator takes the translator when it is built).
 */
final class TranslationServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(TranslationOptions::class, static function (ContainerInterface $container): TranslationOptions {
            $config = $container->get(Configuration::class);
            $translation = $config->get('translation', []);
            if (!is_array($translation)) {
                throw new InvalidArgumentException('The translation configuration must be an array.');
            }

            return TranslationOptions::from(
                $translation,
                $config->get('app.locale'),
                $config->get('app.fallback_locale'),
                $container->get(Application::class)->getRootDir(),
                $config->get('app.env', 'production') === 'production',
            );
        }, shared: true);
        $this->container->factory(CurrentLocale::class, static fn(ContainerInterface $container): CurrentLocale => new CurrentLocale($container->get(TranslationOptions::class)->locale), shared: true);
        $this->container->factory(SymfonyTranslator::class, static fn(ContainerInterface $container): SymfonyTranslator => self::symfony(
            $container->get(TranslationOptions::class),
            $container->get(CurrentLocale::class),
            $container->get(Application::class)->getRootDir(),
        ), shared: true);
        $this->container->alias(TranslatorInterface::class, SymfonyTranslator::class);
        $this->container->factory(Translator::class, static function (ContainerInterface $container): Translator {
            $options = $container->get(TranslationOptions::class);

            return new Translator($container->get(SymfonyTranslator::class), $container->get(CurrentLocale::class), $options->domain, $options->format === 'icu');
        }, shared: true);
        $this->container->factory(LocaleMiddleware::class, static fn(ContainerInterface $container): LocaleMiddleware => new LocaleMiddleware(
            $container->get(CurrentLocale::class),
            $container->get(TranslationOptions::class),
        ));
        $this->container->bind(MissingCommand::class);
        $this->container->bind(MakeLangCommand::class);

        // trans() and locale() in templates, added when the view handler is built.
        if ($this->container->has(ViewHandlerInterface::class)) {
            $this->container->extend(ViewHandlerInterface::class, static function (mixed $handler, ContainerInterface $container): mixed {
                if ($handler instanceof ViewFunctionsInterface) {
                    $handler->addFunction('trans', static fn(string $key, array $parameters = [], ?string $locale = null): string => $container->get(Translator::class)->trans($key, $parameters, $locale));
                    $handler->addFunction('locale', static fn(): string => $container->get(CurrentLocale::class)->get());
                }

                return $handler;
            });
        }
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $commands = $this->container->get(CommandRegistry::class);
            $commands->register('translation:missing', MissingCommand::class, 'List the keys of app.locale that other locales do not translate.');
            $commands->register('make:lang', MakeLangCommand::class, 'Create the message files of a locale from app.locale\'s.');
        }
    }

    /** Symfony's translator with the message files of every locale, and the validator's own translations. */
    private static function symfony(TranslationOptions $options, CurrentLocale $current, string $rootDir): SymfonyTranslator
    {
        $translator = new SymfonyTranslator($options->locale, null, $options->cache ? $rootDir . '/var/cache/translations' : null);
        $translator->setFallbackLocales([$options->fallback]);
        $translator->addLoader('php', new PhpFileLoader());
        $translator->addLoader('xlf', new XliffFileLoader());
        $validators = self::validatorTranslations();
        foreach (array_unique([...$options->locales, $options->fallback]) as $locale) {
            if ($validators !== null) {
                foreach (array_unique([$locale, strtolower((string) strtok($locale, '_'))]) as $candidate) {
                    $file = "{$validators}/validators.{$candidate}.xlf";
                    if (is_file($file)) {
                        $translator->addResource('xlf', $file, $locale, 'validators');
                        break;
                    }
                }
            }
            foreach (glob("{$options->path}/{$locale}/*.php") ?: [] as $file) {
                $domain = basename($file, '.php');
                $translator->addResource('php', $file, $locale, $options->format === 'icu' && $domain !== 'validators' ? $domain . MessageCatalogue::INTL_DOMAIN_SUFFIX : $domain);
            }
        }
        $translator->setLocale($current->get());
        $current->listen(static function (string $locale) use ($translator): void {
            $translator->setLocale($locale);
        });

        return $translator;
    }

    /** The directory of symfony/validator's translations, when the component is installed. */
    private static function validatorTranslations(): ?string
    {
        if (!class_exists(Validation::class)) {
            return null;
        }
        $file = (new ReflectionClass(Validation::class))->getFileName();
        $directory = $file === false ? null : dirname($file) . '/Resources/translations';

        return $directory !== null && is_dir($directory) ? $directory : null;
    }
}
