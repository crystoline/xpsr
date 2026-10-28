<?php

declare(strict_types=1);

// Router script for PHP's built-in server, used by the client tests.
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/redirect') {
    header('Location: /echo?redirected=1', true, 302);

    return true;
}
if ($path === '/slow') {
    sleep(3);
}
if (preg_match('#\A/status/(\d{3})\z#', $path, $match)) {
    http_response_code((int) $match[1]);
}

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}
header('Content-Type: application/json');
header('X-Fixture: yes');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => (string) file_get_contents('php://input'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

return true;
