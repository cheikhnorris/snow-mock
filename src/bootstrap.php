<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/business_rules.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/ui/layout.php';
require_once __DIR__ . '/ui/pages.php';
require_once __DIR__ . '/ui/backend.php';

function route(string $path, string $method): void
{
    if ($path === '/oauth_token.do') {
        oauth_token_endpoint();
        return;
    }
    if (str_starts_with($path, '/api/now/table/')) {
        api_table(substr($path, strlen('/api/now/table/')), $method);
        return;
    }
    session_start();
    ui_route($path, $method);
}
