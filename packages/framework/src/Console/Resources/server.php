<?php

declare(strict_types=1);

// Router script for PHP's development server. Only public files may bypass the front controller.
$public = realpath($_SERVER['DOCUMENT_ROOT']);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = str_contains($path, "\0") ? false : realpath($public . '/' . $path);
$hidden = preg_match('#(?:^|/)\.#', str_replace('\\', '/', $path));
if (!$hidden && $file !== false && is_file($file) && str_starts_with($file, $public . DIRECTORY_SEPARATOR)) {
    return false;
}

require $public . '/index.php';
