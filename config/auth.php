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
        // (revocable tokens in a table, after php xpsr make:token-table) needs crystoline/xpsr-database,
        // as does sanctum (Laravel Sanctum's personal_access_tokens table; see docs/AUTH.md). oauth2
        // (tokens from crystoline/xpsr-auth-oauth2-server) lets other applications act for your users.
        'api' => ['driver' => env('AUTH_API_DRIVER', 'jwt'), 'provider' => 'users'],
        // Admins in their own table, signed in separately:
        // 'admin' => ['driver' => 'session', 'provider' => 'admins', 'login_path' => '/admin/login', 'home_path' => '/admin'],
    ],
    // Where each kind of user is looked up: database (a table, crystoline/xpsr-database), doctrine
    // (an entity, crystoline/xpsr-orm-doctrine), eloquent (a model, crystoline/xpsr-orm-eloquent),
    // cycle (an entity, crystoline/xpsr-orm-cycle), or a UserProviderInterface service.
    'providers' => [
        'users' => ['driver' => 'database', 'table' => 'users', 'login' => 'email', 'password' => 'password'],
        // 'admins' => ['driver' => 'database', 'table' => 'admins', 'login' => 'email', 'password' => 'password'],
    ],
    'login_path' => '/login',
    'home_path' => '/',
    // Model class => policy class, for Gate::allows() and {{ can() }}.
    'policies' => [],
    // How new password hashes are made: auto (Argon2id when PHP has it, otherwise bcrypt), argon2id, or
    // bcrypt, which keeps the hashes readable by a Laravel application on the same users table. A hash
    // made differently is replaced at the user's next sign-in. options go to password_hash(): cost for
    // bcrypt; memory_cost, time_cost, and threads for Argon2id (PHP's defaults when unset).
    'password' => ['algorithm' => env('PASSWORD_ALGORITHM', 'auto'), 'options' => []],
    'jwt' => [
        // php xpsr auth:jwt-secret --write
        'secret' => env('AUTH_JWT_SECRET'),
        'ttl' => '1 hour',
    ],
    'tokens' => [
        'table' => 'api_tokens',
    ],
];
