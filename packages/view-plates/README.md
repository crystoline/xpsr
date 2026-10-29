# crystoline/xpsr-view-plates

[Plates](https://platesphp.com/) views for XPSR applications: native PHP templates in place of Twig.

```sh
composer require crystoline/xpsr-view-plates
```

```php
// config/app.php
'view_engine' => env('VIEW_ENGINE', 'plates'),
```

`views/home/index.php` is the view `home.index`:

```php
<?php $this->layout('layouts/main', ['title' => 'Tasks']) ?>

<h1>Hello, <?= $this->e($user->name) ?></h1>
<?php if ($this->can('update', $task)): ?>
    <form method="post" action="/tasks/<?= $this->e($task->id) ?>">
        <?= $this->csrf_field() ?>
        <input name="title">
    </form>
<?php endif ?>
```

Plates prints whatever a template echoes, so escape every value with `$this->e()`. The [template functions](../../docs/VIEWS.md#template-functions) are methods of `$this`; `csrf_field()` returns HTML to print as it is. Layouts, sections, and `insert()` work as in the [Plates documentation](https://platesphp.com/templates/layouts/).

`PlatesViewHandler::engine()` returns the Plates `Engine`, for folders, shared data, and extensions. Templates are PHP, so OPcache caches them and `app.view_cache_dir` is not used.

Requires PHP 8.4+.
