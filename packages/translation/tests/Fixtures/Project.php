<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\RouteServiceRegistry;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\Translation\CurrentLocale;
use Crystoline\Xpsr\Translation\Http\LocaleMiddleware;
use Crystoline\Xpsr\Translation\TranslationServiceRegistry;
use Crystoline\Xpsr\Translation\Translator;
use Crystoline\Xpsr\Validation\Symfony\ValidationServiceRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A temporary project with message files for en (messages, emails) and fr (messages, with gaps),
 * pt_BR served without files, a Twig view using trans(), routes using the helpers and the
 * middleware, the validation package, and the translation provider. The simple format by default.
 */
final class Project
{
    public readonly string $root;
    public readonly Application $application;

    /** @param array<string, mixed> $translation the "translation" configuration */
    public function __construct(array $translation = [], string $locale = 'en', string $fallback = 'en')
    {
        $this->root = sys_get_temp_dir() . '/xpsr-translation-' . bin2hex(random_bytes(6));
        foreach (['config', 'routes', 'views', 'lang/en', 'lang/fr', 'lang-icu/en', 'var'] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }
        file_put_contents($this->root . '/lang/en/messages.php', <<<'PHP'
            <?php
            return [
                'welcome' => 'Welcome, %name%!',
                'nav' => ['home' => 'Home', 'about' => 'About'],
                'only_en' => 'English only',
                'locale' => 'en',
            ];
            PHP);
        file_put_contents($this->root . '/lang/en/emails.php', <<<'PHP'
            <?php
            return ['receipt' => ['subject' => 'Your receipt', 'body' => 'Thank you, %name%.']];
            PHP);
        file_put_contents($this->root . '/lang/fr/messages.php', <<<'PHP'
            <?php
            return [
                'welcome' => 'Bienvenue, %name% !',
                'nav' => ['home' => 'Accueil'],
                'locale' => 'fr',
            ];
            PHP);
        file_put_contents($this->root . '/lang-icu/en/messages.php', <<<'PHP'
            <?php
            return [
                'welcome' => 'Welcome, {name}!',
                'items' => '{count, plural, =0 {No items} one {# item} other {# items}}',
            ];
            PHP);
        file_put_contents($this->root . '/views/hello.twig', "{{ trans('messages.welcome', {name: name}) }} ({{ locale() }})\n");
        file_put_contents($this->root . '/routes/web.php', <<<'PHP'
            <?php
            use Crystoline\Xpsr\Routing\RouteCollection;
            use Crystoline\Xpsr\Translation\Http\LocaleMiddleware;
            use Crystoline\Xpsr\Translation\Tests\Fixtures\Controllers;

            return static function (RouteCollection $routes): void {
                $routes->get('/hello', [Controllers::class, 'hello']);
                $routes->get('/{locale}/hello', [Controllers::class, 'hello'])->middleware(LocaleMiddleware::class);
                $routes->get('/view', [Controllers::class, 'view']);
                $routes->post('/validate', [Controllers::class, 'validate']);
            };
            PHP);
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.name', 'Shop');
        $config->set('app.env', 'local');
        $config->set('app.locale', $locale);
        $config->set('app.fallback_locale', $fallback);
        $config->set('app.view_dir', $this->root . '/views');
        $config->set('app.view_engine', 'twig');
        $config->set('app.middleware', [LocaleMiddleware::class]);
        $config->set('app.registry', [
            FixtureRegistry::class,
            RouteServiceRegistry::class,
            ViewServiceRegistry::class,
            ConsoleServiceRegistry::class,
            ValidationServiceRegistry::class,
            TranslationServiceRegistry::class,
        ]);
        $config->set('routing', ['adapter' => 'fastroute', 'files' => ['routes/web.php']]);
        $config->set('translation', $translation + ['locales' => ['en', 'fr', 'pt_BR'], 'format' => 'simple']);
    }

    public function boot(): Application
    {
        $this->application->boot();

        return $this->application;
    }

    /** @param array<string, string> $headers @param array<string, string> $cookies @param array<string, mixed>|null $json */
    public function request(string $method, string $uri, array $headers = [], array $cookies = [], ?array $json = null): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'http://shop.example' . $uri)->withCookieParams($cookies);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($json !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withHeader('Accept', 'application/json')
                ->withBody($factory->createStream((string) json_encode($json)));
        }

        return $this->application->getHttpKernel()->handle($request);
    }

    public function translator(): Translator
    {
        return $this->application->get(Translator::class);
    }

    public function locale(): CurrentLocale
    {
        return $this->application->get(CurrentLocale::class);
    }

    public function remove(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
