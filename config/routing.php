<?php

declare(strict_types=1);

return [
    'adapter' => env('ROUTER_ADAPTER', 'fastroute'),
    'files' => ['routes/api.php', 'routes/web.php'],
];
