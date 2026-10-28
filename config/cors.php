<?php

declare(strict_types=1);

// Cross-origin requests from browsers, answered by Crystoline\Xpsr\Http\Middleware\CorsMiddleware.
// With no allowed origins (the default) the middleware does nothing. See docs/HTTP.md.
return [
    // Comma-separated in CORS_ALLOWED_ORIGINS: https://app.example.com, https://*.example.com, or *.
    'allowed_origins' => (string) env('CORS_ALLOWED_ORIGINS', ''),
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    'exposed_headers' => [],
    'allow_credentials' => false,
    'max_age' => 600,
];
