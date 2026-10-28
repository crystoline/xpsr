<?php

declare(strict_types=1);

// Sessions for the routes in routes/web.php (Crystoline\Xpsr\Session\SessionMiddleware). See docs/SESSIONS.md.
return [
    // file (var/sessions); cache (crystoline/xpsr-cache); database (crystoline/xpsr-database, after
    // php xpsr make:session-table); array (in memory, for tests); or a SessionStoreInterface service.
    'store' => env('SESSION_STORE', 'file'),
    // How long a session lasts without requests.
    'lifetime' => env('SESSION_LIFETIME', '2 hours'),
    'cookie' => [
        'name' => 'xpsr_session',
        // null: Secure on HTTPS requests. Behind a proxy that terminates HTTPS, list it in TRUSTED_PROXIES.
        'secure' => null,
        // Lax: not sent with forms posted from other sites. Strict: not sent when arriving from a link either.
        'same_site' => 'Lax',
    ],
];
