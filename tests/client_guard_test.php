<?php
// Self-test for the loopback guard. Run: php tests/client_guard_test.php (exit 0 = pass).
// Also runs public/index.php as a non-local client and expects the 403 text before any routing.
declare(strict_types=1);

require __DIR__ . '/../src/client_guard.php';

// Before any output (CLI would otherwise report headers already sent).
$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
$_SERVER['REQUEST_URI'] = '/api/now/table/u_pac_rule';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include __DIR__ . '/../public/index.php';
$body = ob_get_clean();

$failures = 0;
function check(bool $ok, string $what): bool
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
    return $ok;
}

check(client_allowed('127.0.0.1', []), 'IPv4 loopback allowed');
check(client_allowed('127.10.0.2', []), '127.0.0.0/8 allowed');
check(client_allowed('::1', []), 'IPv6 loopback allowed');
check(client_allowed('::ffff:127.0.0.1', []), 'IPv4-mapped loopback allowed');
check(!client_allowed('10.1.2.3', []), 'LAN client refused by default');
check(!client_allowed('', []), 'missing REMOTE_ADDR refused');
check(!client_allowed('::ffff:10.1.2.3', []), 'IPv4-mapped LAN client refused');
check(client_allowed('10.1.2.3', ['10.1.2.0/24']), 'client inside an allowed CIDR');
check(!client_allowed('10.1.3.3', ['10.1.2.0/24']), 'client outside the allowed CIDR');
check(client_allowed('192.168.1.20', ['192.168.1.20']), 'exact allowed address');
check(!client_allowed('192.168.1.21', ['192.168.1.20', 'garbage', '1.2.3.4/99']), 'invalid entries ignored');
check(client_allowed('8.8.8.8', ['0.0.0.0/0']), '0.0.0.0/0 allows everyone (explicit opt-in only)');

putenv('SNOW_MOCK_ALLOWED_CLIENTS=172.16.0.0/12, 10.9.9.9');
check(allowed_clients(['allowed_clients' => ['10.0.0.1']]) === ['10.0.0.1', '172.16.0.0/12', '10.9.9.9'], 'env adds to config');
putenv('SNOW_MOCK_ALLOWED_CLIENTS');


check(str_starts_with($body, '403 Forbidden'), 'index.php answers 403 to a non-local client')
    || print('     got: ' . substr($body, 0, 200) . "\n");

exit($failures ? 1 : 0);
