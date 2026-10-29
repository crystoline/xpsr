# crystoline/xpsr-view-latte

[Latte](https://latte.nette.org/) views for XPSR applications, in place of Twig. Latte escapes each value for where it is printed: HTML text, an attribute, JavaScript, CSS, or a URL.

```sh
composer require crystoline/xpsr-view-latte
```

```php
// config/app.php
'view_engine' => env('VIEW_ENGINE', 'latte'),
'view_cache_dir' => dirname(__DIR__) . '/var/cache/views',
```

`views/home/index.latte` is the view `home.index`:

```latte
{layout '../layouts/main.latte'}

{block content}
    <h1>Hello, {$user->name}</h1>
    {if can('update', $task)}
        <form method="post" action="/tasks/{$task->id}">
            {csrf_field()}
            <input name="title">
        </form>
    {/if}
{/block}
```

The [template functions](../../docs/VIEWS.md#template-functions) are called by name; `csrf_field()` prints its HTML as it is.

Compiled templates go to `app.view_cache_dir`; without one, each request compiles them again. With `app.debug` off, changed templates are not compiled again, so clear the directory on deploy. `LatteViewHandler::engine()` returns the Latte `Engine`, for filters, extensions, and other settings.

Requires PHP 8.4+.
