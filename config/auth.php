<?php

declare(strict_types=1);

// Authentication and authorization (Crystoline\Xpsr\Registry\AuthServiceRegistry). See docs/AUTH.md.
return [
    // The guard Authenticate and Guest use when none is named.
    'default' => 'web',
    'guards' => [
        // Browsers: the user is kept in the session (routes/web.php).
        'web' => ['driver' => 'session', 'provider' => 'users'],
        // API clients: "Authorization: Bearer <token>". jwt needs crystoline/xpsr-auth-jwt; token
        // (revocable tokens in a table, after php xpsr make:token-table) needs crystoline/xpsr-database.
        'api' => ['driver' => env('AUTH_API_DRIVER', 'jwt'), 'provider' => 'users'],
        // Admins in their own table, signed in separately:
        // 'admin' => ['driver' => 'session', 'provider' => 'admins', 'login_path' => '/admin/login', 'home_path' => '/admin'],
    ],
    // Where each kind of user is looked up: database (a table, crystoline/xpsr-database), doctrine
    // (an entity, crystoline/xpsr-orm-doctrine), eloquent (a model, crystoline/xpsr-orm-eloquent),
    // or a UserProviderInterface service.
    'providers' => [
        'users' => ['driver' => 'database', 'table' => 'users', 'login' => 'email', 'password' => 'password'],
        // 'admins' => ['driver' => 'database', 'table' => 'admins', 'login' => 'email', 'password' => 'password'],
    ],
    'login_path' => '/login',
    'home_path' => '/',
    // Model class => policy class, for Gate::allows() and {{ can() }}.
    'policies' => [],
    'jwt' => [
        // php xpsr auth:jwt-secret --write
        'secret' => env('AUTH_JWT_SECRET'),
        'ttl' => '1 hour',
    ],
    'tokens' => [
        'table' => 'api_tokens',
    ],
];
