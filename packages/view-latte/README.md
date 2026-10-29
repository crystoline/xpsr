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

Compiled templates go to `app.view_cache_dir`; without one, each request compiles them again. A changed template is compiled again even with `app.debug` off. Latte compares each template's content, which costs about 0.2 ms for a page of three templates. To skip the check, call `engine()->setAutoRefresh(false)` from a service registry's `boot()`, and run `php xpsr view:clear` on every deploy. `LatteViewHandler::engine()` returns the Latte `Engine`, for filters, extensions, and other settings.

Requires PHP 8.4+.
