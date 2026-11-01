<?php
// Loopback guard: the mock has demo passwords and no CSRF protection, so only local clients may use it.
// Extra clients are allowed with config.php 'allowed_clients' or env SNOW_MOCK_ALLOWED_CLIENTS
// (comma-separated IPv4 CIDRs or exact addresses, e.g. "10.0.0.0/24,192.168.1.20").
declare(strict_types=1);

function client_is_loopback(string $ip): bool
{
    if ($ip === '::1') {
        return true;
    }
    if (str_starts_with(strtolower($ip), '::ffff:')) { // IPv4-mapped IPv6
        $ip = substr($ip, 7);
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ip_in_cidr($ip, '127.0.0.0/8');
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if ($cidr === '') {
        return false;
    }
    if (!str_contains($cidr, '/')) {
        return inet_pton($ip) !== false && @inet_pton($cidr) !== false && inet_pton($ip) === inet_pton($cidr);
    }
    [$net, $bits] = explode('/', $cidr, 2);
    if (!ctype_digit($bits) || filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false; // only IPv4 networks are supported
    }
    $bits = (int)$bits;
    if ($bits < 0 || $bits > 32) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
    return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
}

/** @param string[] $allowed */
function client_allowed(string $ip, array $allowed): bool
{
    if (client_is_loopback($ip)) {
        return true;
    }
    foreach ($allowed as $cidr) {
        if (ip_in_cidr($ip, (string)$cidr)) {
            return true;
        }
    }
    return false;
}

/** Allowed extra clients: config.php 'allowed_clients' plus env SNOW_MOCK_ALLOWED_CLIENTS. */
function allowed_clients(array $config): array
{
    $list = $config['allowed_clients'] ?? [];
    $env = getenv('SNOW_MOCK_ALLOWED_CLIENTS');
    if (is_string($env) && trim($env) !== '') {
        $list = array_merge($list, explode(',', $env));
    }
    return array_values(array_filter(array_map('trim', $list), 'strlen'));
}
