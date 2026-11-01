<?php
// Front controller and router script for `php -S`.
require_once __DIR__ . '/../src/client_guard.php';
if (!client_allowed($_SERVER['REMOTE_ADDR'] ?? '', allowed_clients(require __DIR__ . '/../config.php'))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "403 Forbidden: the ServiceNow mock only accepts local clients (see allowed_clients in config.php).\n";
    return true;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false; // static asset
}
require __DIR__ . '/../src/bootstrap.php';
route($path, $_SERVER['REQUEST_METHOD']);
