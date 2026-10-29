# Views

A view is a template under `views/`, named with dots for directories: `home.index` is `views/home/index.twig` with Twig. Controllers return one as an HTML response:

```php
return view('home.index', ['user' => $user]);
```

`view()` renders through `Crystoline\Xpsr\View\ViewHandlerInterface`, which services can also take to render a string, for example for an email body:

```php
$html = $views->render('emails.welcome', ['user' => $user]);
```

A view without a template throws `Crystoline\Xpsr\View\ViewNotFoundException`, whose `view` property names it.

## Engines

Twig is the default. `app.view_engine` in `config/app.php` (or `VIEW_ENGINE`) picks another:

| Engine | `app.view_engine` | Package | Template | Escaping |
|---|---|---|---|---|
| [Twig](https://twig.symfony.com/) | `twig` | `twig/twig` (in the skeleton) | `views/home/index.twig` | automatic |
| [Plates](https://platesphp.com/) | `plates` | [`crystoline/xpsr-view-plates`](../packages/view-plates/README.md) | `views/home/index.php` | by hand, with `$this->e()` |
| [Latte](https://latte.nette.org/) | `latte` | [`crystoline/xpsr-view-latte`](../packages/view-latte/README.md) | `views/home/index.latte` | automatic, by context |
| [Blade](https://laravel.com/docs/blade) | `blade` | [`crystoline/xpsr-view-blade`](../packages/view-blade/README.md) | `views/home/index.blade.php` | automatic, `{{ }}` |

```sh
composer require crystoline/xpsr-view-latte
```

```php
// config/app.php
'view_engine' => env('VIEW_ENGINE', 'latte'),
```

`app.view_dir` is the template directory and `app.view_cache_dir` is where Twig, Latte, and Blade keep compiled templates (`false`: Twig and Latte compile on each request, Blade uses a directory under the system temporary directory). With `app.debug` off, Twig and Latte do not look for changed templates, so clear the cache directory on deploy.

## Template functions

The view provider gives every engine the same functions:

| Function | Returns |
|---|---|
| `csrf_token()` | The session's CSRF token. |
| `csrf_field()` | A hidden `_token` input with it, as HTML. |
| `session('key', $default)` | A session value, such as a flash message. |
| `auth_user()` | The signed-in user, or null. |
| `can('update', $task)` | Whether the signed-in user may, by the gate's policies. |

The CSRF functions and `session()` need a route behind `SessionMiddleware`; see [SESSIONS.md](SESSIONS.md). `auth_user()` and `can()` need `Authenticate` (optional or required) on the route; see [AUTH.md](AUTH.md). Each engine calls them its own way:

| Engine | Form field | A value | A condition |
|---|---|---|---|
| Twig | `{{ csrf_field() }}` | `{{ session('status') }}` | `{% if can('update', task) %}` |
| Plates | `<?= $this->csrf_field() ?>` | `<?= $this->e($this->session('status')) ?>` | `<?php if ($this->can('update', $task)): ?>` |
| Latte | `{csrf_field()}` | `{session('status')}` | `{if can('update', $task)}` |
| Blade | `@csrf` or `{{ $xpsr->csrf_field() }}` | `{{ $xpsr->session('status') }}` | `@if ($xpsr->can('update', $task))` |

An application adds its own through `Crystoline\Xpsr\View\ViewFunctionsInterface`, which every official engine implements, before the first render. A service registry's `boot()` is the place; it builds the view handler on each request, which costs little because templates are compiled only when they are rendered:

```php
$views = $this->container->get(ViewHandlerInterface::class);
if ($views instanceof ViewFunctionsInterface) {
    $views->addFunction('asset', static fn(string $path): string => '/build/' . $path);
    $views->addFunction('icon', static fn(string $name): string => "<svg><use href=\"/icons.svg#{$name}\"/></svg>", html: true);
}
```

A function's result is escaped like any value unless it is added with `html: true`, which says it returns safe HTML. Plates escapes nothing by itself, so its templates call `$this->e()` for everything else.

## Your own engine

`app.view_engine` can also name a class that implements `ViewHandlerInterface` (and `ViewFunctionsInterface`, for the template functions); the container builds it, so a factory in `config/factory.php` can give it its settings. `Crystoline\Xpsr\Testing\ViewHandlerContractTestCase` is the behavior every engine must have: extend it in the engine's tests, as the official packages do, and implement `createHandler()`, `extension()`, and `templates()`.
