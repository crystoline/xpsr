<?php

declare(strict_types=1);

// Authentication and authorization (Crystoline\Xpsr\Registry\AuthServiceRegistry). See docs/AUTH.md.
return [
    // The guard Authenticate uses when none is named.
    'default' => 'web',
    'guards' => [
        // Browsers: the user is kept in the session (routes/web.php).
        'web' => ['driver' => 'session'],
        // API clients: "Authorization: Bearer <token>". jwt needs crystoline/xpsr-auth-jwt; token
        // (revocable tokens in a table, after php xpsr make:token-table) needs crystoline/xpsr-database.
        'api' => ['driver' => env('AUTH_API_DRIVER', 'jwt')],
    ],
    // Where users are looked up: database (a table, crystoline/xpsr-database), doctrine
    // (an entity, crystoline/xpsr-orm-doctrine), or a UserProviderInterface service.
    'users' => [
        'driver' => 'database',
        'table' => 'users',
        'login' => 'email',
        'password' => 'password',
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
