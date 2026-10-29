# crystoline/xpsr-view-blade

[Blade](https://laravel.com/docs/blade) views for XPSR applications, without the rest of Laravel: `illuminate/view` in place of Twig.

```sh
composer require crystoline/xpsr-view-blade
```

```php
// config/app.php
'view_engine' => env('VIEW_ENGINE', 'blade'),
'view_cache_dir' => dirname(__DIR__) . '/var/cache/views',
```

`views/home/index.blade.php` is the view `home.index`:

```blade
@extends('layouts.main')

@section('content')
    <h1>Hello, {{ $user->name }}</h1>
    @if ($xpsr->can('update', $task))
        <form method="post" action="/tasks/{{ $task->id }}">
            @csrf
            <input name="title">
        </form>
    @endif
@endsection
```

The [template functions](../../docs/VIEWS.md#template-functions) are methods of `$xpsr`, which every view has: `{{ $xpsr->session('status') }}`, `$xpsr->auth_user()`. `@csrf` prints `csrf_field()`. `{{ }}` escapes values, and prints the HTML of functions such as `csrf_field()` as it is.

Layouts, sections, stacks, `@include`, and the control structures work. Components (`<x-...>`) and directives that call Laravel's own helpers, such as `@method`, need parts of a Laravel application and are not supported.

Compiled templates go to `app.view_cache_dir`, or without one to a directory under the system temporary directory, and are compiled again when their file changes. `BladeViewHandler::compiler()` returns the Blade compiler, for custom directives, and `factory()` the view factory, for shared data.

Requires PHP 8.4+.
