<?php

declare(strict_types=1);

use Crystoline\Http\ResponseEmitter;
use Psr\Http\Message\ServerRequestInterface;

try {
    $application = require dirname(__DIR__) . '/bootstrap/app.php';
    $request = $application->get(ServerRequestInterface::class);
    $response = $application->getHttpKernel()->handle($request);

    (new ResponseEmitter())->emit($response);
} catch (Throwable $exception) {
    // Bootstrap and emission failures happen outside the HTTP kernel's error handling.
    error_log((string) $exception);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Internal Server Error';
    }
}
