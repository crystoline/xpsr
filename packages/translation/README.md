# crystoline/xpsr-translation

Translations for XPSR applications, on Symfony's Translation component. Messages live in PHP files per locale, each request gets its locale from its route, session, cookie, or `Accept-Language` header, `trans()` works in code and in every view engine, and validation messages come in the request's language.

```sh
composer require crystoline/xpsr-translation
```

Add the provider to `app.registry` in `config/app.php`, after the view and validation providers, and the middleware to `app.middleware`:

```php
'registry' => [
    // ...
    Crystoline\Xpsr\Translation\TranslationServiceRegistry::class,
],
'middleware' => [
    // ...
    Crystoline\Xpsr\Translation\Http\LocaleMiddleware::class,
],
```

`app.locale` and `app.fallback_locale` in `config/app.php` name the default locale and the one a missing translation falls back to. `config/translation.php` lists the locales the application serves:

```php
return [
    'locales' => ['en', 'fr', 'pt_BR'],
];
```

## Message files

A file per locale and domain, `lang/<locale>/<domain>.php`, returning an array; nested arrays become dotted keys:

```php
// lang/en/messages.php
return [
    'welcome' => 'Welcome, {name}!',
    'items' => '{count, plural, =0 {No items} one {# item} other {# items}}',
    'nav' => ['home' => 'Home', 'about' => 'About'],
];

// lang/fr/messages.php
return [
    'welcome' => 'Bienvenue, {name} !',
    'nav' => ['home' => 'Accueil'],
];
```

```php
trans('messages.welcome', ['name' => 'Ada']);   // Welcome, Ada!
trans('welcome', ['name' => 'Ada']);            // the same: messages is the default domain
trans('messages.nav.home');                     // Home
trans('emails.receipt.subject');                // lang/<locale>/emails.php, key receipt.subject
trans('messages.items', ['count' => 3]);        // 3 items
trans('messages.nav.about', locale: 'fr');      // About: fr has no translation, en is the fallback
trans('messages.nope');                         // messages.nope: a missing key comes back as given
```

A key's first segment names the domain when a file of that name exists in the locale or its fallback; otherwise the key is looked up in `translation.domain`, `messages` by default. A missing key comes back as given, after the fallback locale was tried.

**Placeholders and plurals.** With `ext-intl`, messages are in the [ICU format](https://unicode-org.github.io/icu/userguide/format_parse/messages/): `{name}` placeholders, `{count, plural, ...}`, `{gender, select, ...}`, and `{amount, number, currency}`. Without it, the simple format replaces `%name%` placeholders and has no plurals. `translation.format` picks one; the default is `icu` when the extension is loaded.

## The locale of a request

`LocaleMiddleware` sets the request's locale from the first of `translation.resolvers` that has one the application serves, in order: the `{locale}` route parameter, the session value `locale`, the cookie `locale`, and the `Accept-Language` header (by quality; `fr-CA` matches `fr`, `pt` matches `pt_BR`). The locale is the `locale` request attribute, `locale()` in code and templates, and `CurrentLocale` as a service; after the request, `app.locale` comes back.

In `app.middleware` the middleware sees the session, cookie, and header. The route parameter exists only after the route matched, so for localized paths use it as route middleware:

```php
$routes->group('/{locale}', static function (RouteCollection $routes): void {
    $routes->get('/products', [ProductController::class, 'index']);
}, middleware: [LocaleMiddleware::class]);
```

A language switcher sets the cookie or the session value, as the application prefers, and the middleware reads it on the next request. Code can set the locale too: `$translator->setLocale('fr')`, or `CurrentLocale::set()`, for a job that sends a message in the recipient's language.

## In code and templates

The `Translator` service, the `trans()` helper, and the `trans()` and `locale()` functions in templates, for every view engine:

```php
final class ReceiptMailer
{
    public function __construct(private readonly Translator $translator) {}

    public function subject(Order $order): string
    {
        return $this->translator->trans('emails.receipt.subject', ['number' => $order->number], $order->customer->locale);
    }
}
```

```twig
<h1>{{ trans('messages.welcome', {name: user.name}) }}</h1>
<html lang="{{ locale() }}">
```

`Translator` also has `has($key)`, `locale()`, `setLocale()`, `domains()`, `messages($domain)`, and `symfony()` for the component's own API.

## Validation messages

With [`crystoline/xpsr-validation`](../validation/README.md), the validator takes the translator, and Symfony's own translations of its constraint messages come with the component in some sixty languages: a request in French gets "Cette valeur ne doit pas être vide." where an English one gets "This value should not be blank.". A `lang/<locale>/validators.php` file overrides them.

## Commands

```sh
php xpsr translation:missing        # the keys app.locale has that each other locale lacks; exit 1 when any
php xpsr translation:missing fr     # one locale
php xpsr make:lang de               # lang/de/*.php from app.locale's files, with its texts to translate
```

## Configuration

`config/translation.php` is optional:

```php
return [
    'locales' => ['en'],                    // the locales served; app.locale is always one
    'path' => 'lang',                       // the message files, relative to the project
    'format' => null,                       // icu or simple; null picks icu when ext-intl is loaded
    'cache' => null,                        // compile the catalogues into var/cache/translations; null means in production
    'resolvers' => ['route', 'session', 'cookie', 'header'],
    'route_parameter' => 'locale',
    'session_key' => 'locale',
    'cookie' => 'locale',
    'domain' => 'messages',                 // the domain of a key without one
];
```

Invalid values fail when translations are first used, with the setting's name. In production the catalogues are compiled into `var/cache/translations` once; clear that directory after changing message files there.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Translation\Translator` | `trans()`, `has()`, `locale()`, `setLocale()`, `domains()`, `messages()`. Shared. |
| `Crystoline\Xpsr\Translation\CurrentLocale` | The locale of the current request, command, or job: `get()`, `set()`, `reset()`. Shared. |
| `Crystoline\Xpsr\Translation\Http\LocaleMiddleware` | Sets it from the request. |
| `Symfony\Contracts\Translation\TranslatorInterface` | Symfony's translator underneath, which the validator uses. Shared. |

Not provided: translations stored in a database, localized route paths, extracting messages from source code, and formatting dates and numbers outside messages, which `intl` does on its own.

Requires PHP 8.4+, `symfony/translation` and `symfony/config` 7.4 or 8. `ext-intl` is optional; the validation package is detected.
