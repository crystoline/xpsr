<?php

declare(strict_types=1);

// Database connections, used by the optional crystoline/xpsr-database package:
//   composer require crystoline/xpsr-database
// then add Crystoline\Database\DatabaseServiceRegistry::class to app.registry in config/app.php.
// Connection options are Doctrine DBAL connection parameters; see packages/database/README.md.
return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        // A file database: no server needed. The path is relative to the project root.
        'sqlite' => [
            'driver' => 'pdo_sqlite',
            'path' => env('DB_DATABASE', 'var/database.sqlite'),
        ],
        'mysql' => [
            'url' => env('DATABASE_URL'),   // mysql://user:secret@127.0.0.1:3306/app, used instead of the settings below when set
            'driver' => 'pdo_mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'dbname' => env('DB_DATABASE', 'app'),
            'user' => env('DB_USERNAME', 'app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
        ],
        'pgsql' => [
            'url' => env('DATABASE_URL'),   // postgresql://user:secret@127.0.0.1:5432/app
            'driver' => 'pdo_pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 5432),
            'dbname' => env('DB_DATABASE', 'app'),
            'user' => env('DB_USERNAME', 'app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
        ],
    ],

    'migrations' => [
        'path' => 'database/migrations',
        'table' => 'migrations',
    ],

    // Log every query at debug level through the application logger.
    'log_queries' => filter_var(env('DB_LOG_QUERIES', false), FILTER_VALIDATE_BOOL),
];
