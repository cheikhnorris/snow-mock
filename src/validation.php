<?php
// Validation rules V1-V12 from "PAC Sync - ServiceNow Implementation Requirements".
// Same checks as pac_sync/normalizer.py, so both sides agree on what a valid rule is.

const FORBIDDEN_CHARS = ['"', "'", ';', '\\', "\r", "\n", "\t", ' '];
const PUBLIC_SUFFIXES = ['com', 'net', 'org', 'io', 'info', 'biz', 'local', 'corp', 'co.uk', 'org.uk', 'com.au',
    'co.jp', 'com.br', 'co.in', 'de', 'fr', 'es', 'it', 'nl', 'be', 'ch', 'uk', 'us', 'ma'];
const LABEL_RE = '(?!-)[a-z0-9-]{1,63}(?<!-)';

/** Printable ASCII and no forbidden character: shared by the PAC and URL entry checks. */
function value_chars_error(string $value): ?string
{
    // Printable ASCII only (same as normalizer.py NON_PRINTABLE_RE): U+2028 and the like end a line in older PAC engines.
    if (preg_match('/[^!-~]/', $value)) {
        return 'Value must be printable ASCII (no spaces, control or non-ASCII characters)';
    }
    foreach (FORBIDDEN_CHARS as $c) {
        if (str_contains($value, $c)) {
            return 'Value contains a forbidden character (quote, semicolon, backslash or whitespace)';
        }
    }
    return null;
}

/** ZIA object (URL category) name; pac-sync also checks prefix + name length. Pure: no DB access. */
function zia_validate_object_name(string $name): ?string
{
    return preg_match('/^[a-z0-9][a-z0-9-]{0,25}\z/', $name) ? null
        : 'Name must be 1 to 26 characters: lower-case letters, digits and "-", not starting with "-"';
}

/** URL filtering entry (already trimmed and lower-cased): host or .domain, optional path. Pure: no DB access. */
function url_validate_entry(string $value): ?string
{
    if ($value === '' || strlen($value) > 255) {
        return 'URL entry is mandatory and at most 255 characters';
    }
    if ($e = value_chars_error($value)) {
        return $e;
    }
    if (strpbrk($value, '*?#:') !== false) {
        return 'URL entry must not contain * ? # or : (no scheme, port, query or wildcard)';
    }
    [$host, $path] = array_pad(explode('/', $value, 2), 2, null);
    if (str_starts_with($host, '.')) {
        $host = substr($host, 1);
    }
    $re = '/^(?=.{1,253}$)' . LABEL_RE . '(\.' . LABEL_RE . ')*$/';
    if (!preg_match($re, $host) || !str_contains($host, '.')) {
        return 'URL entry must start with a domain of at least two labels, e.g. example.com or .example.com/path';
    }
    if (in_array($host, PUBLIC_SUFFIXES, true)) {
        return "'$host' is a public suffix and cannot be used alone";
    }
    if ($path !== null && !preg_match('#^[a-z0-9._~%/-]*\z#', $path)) {
        return 'URL path may only contain a-z, 0-9 and . _ ~ % / -';
    }
    return null;
}

/** Proxy gateway name as configured in ZIA: printable ASCII, 1 to 128 characters, no quote or backslash. Pure: no DB access. */
function fwd_validate_gateway(string $name): ?string
{
    return preg_match('/^[\x20-\x7e]{1,128}\z/', $name) && !str_contains($name, '"') && !str_contains($name, '\\') ? null
        : 'Proxy gateway must be 1 to 128 printable ASCII characters without " or \\';
}

function pac_validate_value(string $matchType, string $value): ?string
{
    if ($value === '') {
        return 'Value is mandatory';
    }
    if ($e = value_chars_error($value)) {
        return $e;
    }
    $host = '/^(?=.{1,253}$)' . LABEL_RE . '(\.' . LABEL_RE . ')*$/';
    switch ($matchType) {
        case 'host_exact':
            return preg_match($host, $value) ? null
                : 'Host (exact) must be a plain host name: no scheme, port, path or wildcard (e.g. intranet.corp.local)';
        case 'domain_suffix':
            if (!preg_match($host, $value) || !str_contains($value, '.')) {
                return "Domain must have at least two labels, without '*' or a leading '.' (e.g. corp.local)";
            }
            return in_array($value, PUBLIC_SUFFIXES, true) ? "'$value' is a public suffix and cannot be used alone" : null;
        case 'ip_cidr':
            if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $value, $m) || !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return 'IP network must be an IPv4 CIDR such as 10.20.0.0/16';
            }
            $prefix = (int)$m[2];
            if ($prefix < 8 || $prefix > 32) {
                return 'IP network prefix must be between /8 and /32';
            }
            $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
            return (ip2long($m[1]) & ~$mask & 0xFFFFFFFF) === 0 ? null
                : "Host bits are set: use the network address (e.g. " . long2ip(ip2long($m[1]) & $mask) . "/$prefix)";
        case 'url_pattern':
            if (!preg_match('#^https?://[a-z0-9.*\-]+(:\d{1,5})?(/[^\s"\';\\\\]*)?$#', $value)) {
                return 'URL pattern must start with http:// or https:// (e.g. https://sso.partner.com/*)';
            }
            return substr_count($value, '*') > 3 ? "URL pattern allows at most 3 '*'" : null;
        default:
            return "Unknown match type '$matchType'";
    }
}

/** Region sys_ids a rule applies to (global = every active region). */
function rule_region_ids(array $rule): array
{
    if (($rule['u_scope'] ?? '') === 'global') {
        return array_column(rec_query('u_pac_region', 'u_active=true')[0], 'sys_id');
    }
    return array_values(array_filter(explode(',', $rule['u_regions'] ?? '')));
}

/** V10: valid-from / valid-to checks shared by every rule table. Appends to $errors. */
function validate_rule_dates(array $r, ?array $old, array $def, array &$errors): void
{
    $from = $r['u_valid_from'] ?? '';
    $to = $r['u_valid_to'] ?? '';
    $datesChanged = $old === null || $from !== ($old['u_valid_from'] ?? '') || $to !== ($old['u_valid_to'] ?? '');
    foreach (['u_valid_from' => $from, 'u_valid_to' => $to] as $f => $v) {
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
            $errors[] = "{$def[$f]['label']} must be a date/time (YYYY-MM-DD HH:MM:SS)";
        }
    }
    if ($to !== '' && $datesChanged) {
        $start = max(time(), $from !== '' ? strtotime($from . ' UTC') : 0);
        if ($from !== '' && strtotime($to . ' UTC') <= strtotime($from . ' UTC')) {
            $errors[] = 'Valid to must be after valid from';
        } elseif (strtotime($to . ' UTC') <= time()) {
            $errors[] = 'Valid to must be in the future';
        } elseif (strtotime($to . ' UTC') - $start > 365 * 86400) {
            $errors[] = 'Temporary rules can last at most 365 days';
        }
    }
}

/** Throws BusinessRuleException with every failed check. */
function validate_pac_rule(array &$r, ?array $old): void
{
    $errors = [];
    $def = table_def('u_pac_rule')['fields'];

    // V1: trim and lower-case
    $r['u_value'] = mb_strtolower(trim($r['u_value'] ?? ''));

    // The number lands in the PAC as a "// <number> <ACTION>" comment (same check as pac_sync/normalizer.py NUMBER_RE).
    if (($r['number'] ?? '') !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}\z/', $r['number'])) {
        $errors[] = 'Number may only contain letters, digits, _ and - (1 to 40 characters)';
    }

    foreach ($def as $f => $fd) {
        if (!empty($fd['mandatory']) && trim($r[$f] ?? '') === '') {
            $errors[] = "{$fd['label']} is mandatory";
        }
    }
    foreach (['u_action', 'u_match_type', 'u_scope'] as $f) {
        if (($r[$f] ?? '') !== '' && !isset($def[$f]['choices'][$r[$f]])) {
            $errors[] = "Invalid {$def[$f]['label']} '{$r[$f]}'";
        }
    }
    // V2-V5
    if (($r['u_match_type'] ?? '') !== '' && ($e = pac_validate_value($r['u_match_type'], $r['u_value']))) {
        $errors[] = $e;
    }
    // V6
    if (($r['u_action'] ?? '') === 'proxy') {
        $p = $r['u_proxy_profile'] ? rec_get('u_pac_proxy_profile', $r['u_proxy_profile']) : null;
        if (!$p || $p['u_active'] !== 'true') {
            $errors[] = 'Proxy rules need an active proxy profile';
        }
    } elseif (($r['u_action'] ?? '') === 'direct') {
        $r['u_proxy_profile'] = '';
    }
    // V7
    if (($r['u_scope'] ?? '') === 'global') {
        $r['u_regions'] = '';
    } elseif (($r['u_scope'] ?? '') === 'regional') {
        $ids = array_values(array_filter(explode(',', $r['u_regions'] ?? '')));
        $active = array_column(rec_query('u_pac_region', 'u_active=true')[0], 'sys_id');
        if (!$ids) {
            $errors[] = 'Select at least one region for a regional rule';
        } elseif (array_diff($ids, $active)) {
            $errors[] = 'One or more selected regions are unknown or inactive';
        }
        sort($ids);
        $r['u_regions'] = implode(',', $ids);
    }
    // Priority
    if (!preg_match('/^\d+$/', $r['u_priority'] ?? '') || (int)$r['u_priority'] < 1 || (int)$r['u_priority'] > 999) {
        $errors[] = 'Priority must be a whole number between 1 and 999';
    }
    validate_rule_dates($r, $old, $def, $errors); // V10
    // V11
    if (($r['u_scope'] ?? '') === 'global' && ($r['u_change_request'] ?? '') === '') {
        $errors[] = 'Global rules require a change request';
    }
    if ($errors) {
        throw new BusinessRuleException(implode("\n", $errors));
    }

    // V8 / V9: duplicates and conflicts with other live rules in an overlapping region
    $live = 'u_stateINpending_approval,approved,deployed';
    $others = rec_query('u_pac_rule', "u_match_type={$r['u_match_type']}^u_value={$r['u_value']}^$live^sys_id!={$r['sys_id']}")[0];
    $mine = rule_region_ids($r);
    foreach ($others as $o) {
        if ($o['sys_id'] === ($r['u_replaces'] ?? '') || ($o['u_replaces'] ?? '') === $r['sys_id']) {
            continue; // the rule being replaced (modify / extend)
        }
        $overlap = array_intersect($mine, rule_region_ids($o));
        if (!$overlap) {
            continue;
        }
        $regions = implode(', ', array_map(fn($id) => display_value('u_pac_rule', 'u_regions', $id), $overlap));
        $same = $o['u_action'] === $r['u_action'] && $o['u_proxy_profile'] === $r['u_proxy_profile'];
        throw new BusinessRuleException(($same ? 'Duplicate of ' : 'Conflicts with ') . "{$o['number']} "
            . "({$o['u_action']} {$o['u_match_type']} {$o['u_value']}) in $regions");
    }
}

function validate_url_category(array $c): void
{
    $errors = [];
    $def = table_def('u_url_category')['fields'];
    foreach (['u_name', 'u_action'] as $f) {
        if (trim($c[$f] ?? '') === '') {
            $errors[] = "{$def[$f]['label']} is mandatory";
        }
    }
    if (($c['u_name'] ?? '') !== '' && ($e = zia_validate_object_name($c['u_name']))) {
        $errors[] = $e;
    }
    if (($c['u_action'] ?? '') !== '' && !isset($def['u_action']['choices'][$c['u_action']])) {
        $errors[] = "Invalid Action '{$c['u_action']}'";
    }
    if (($c['u_name'] ?? '') !== '' && rec_first('u_url_category', "u_name={$c['u_name']}^sys_id!={$c['sys_id']}")) {
        $errors[] = 'A URL category with this name already exists';
    }
    if ($errors) {
        throw new BusinessRuleException(implode("\n", $errors));
    }
}

/** Throws BusinessRuleException with every failed check. */
function validate_url_rule(array &$r, ?array $old): void
{
    $errors = [];
    $def = table_def('u_url_rule')['fields'];
    $r['u_value'] = mb_strtolower(trim($r['u_value'] ?? '')); // V1

    if (($r['number'] ?? '') !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}\z/', $r['number'])) {
        $errors[] = 'Number may only contain letters, digits, _ and - (1 to 40 characters)';
    }
    foreach ($def as $f => $fd) {
        if (!empty($fd['mandatory']) && trim($r[$f] ?? '') === '') {
            $errors[] = "{$fd['label']} is mandatory";
        }
    }
    if (($r['u_category'] ?? '') !== '') {
        $cat = rec_get('u_url_category', $r['u_category']);
        if (!$cat || $cat['u_active'] !== 'true') {
            $errors[] = 'URL rules need an active URL category';
        }
    }
    if ($r['u_value'] !== '' && ($e = url_validate_entry($r['u_value']))) {
        $errors[] = $e;
    }
    validate_rule_dates($r, $old, $def, $errors);
    if ($errors) {
        throw new BusinessRuleException(implode("\n", $errors));
    }

    // V8 / V9: same entry in another live rule
    $live = 'u_stateINpending_approval,approved,deployed';
    foreach (rec_query('u_url_rule', "u_value={$r['u_value']}^$live^sys_id!={$r['sys_id']}")[0] as $o) {
        if ($o['sys_id'] === ($r['u_replaces'] ?? '') || ($o['u_replaces'] ?? '') === $r['sys_id']) {
            continue; // the rule being replaced
        }
        throw new BusinessRuleException(($o['u_category'] === $r['u_category'] ? 'Duplicate of ' : 'Conflicts with ')
            . "{$o['number']} ({$o['u_value']} in " . display_value('u_url_rule', 'u_category', $o['u_category']) . ')');
    }
}

function validate_fwd_profile(array &$p): void
{
    $errors = [];
    $p['u_proxy_gateway'] = trim($p['u_proxy_gateway'] ?? ''); // V1
    $def = table_def('u_fwd_profile')['fields'];
    foreach (['u_name', 'u_forward_method'] as $f) {
        if (trim($p[$f] ?? '') === '') {
            $errors[] = "{$def[$f]['label']} is mandatory";
        }
    }
    if (($p['u_name'] ?? '') !== '' && ($e = zia_validate_object_name($p['u_name']))) {
        $errors[] = $e;
    }
    $method = $p['u_forward_method'] ?? '';
    $gw = $p['u_proxy_gateway'] ?? '';
    if ($method !== '' && !isset($def['u_forward_method']['choices'][$method])) {
        $errors[] = "Invalid Forward method '$method'";
    } elseif ($method === 'proxychain') {
        if ($gw === '') {
            $errors[] = 'Proxy gateway is mandatory for a proxy chain';
        } elseif ($e = fwd_validate_gateway($gw)) {
            $errors[] = $e;
        }
    } elseif ($method === 'direct' && $gw !== '') {
        $errors[] = 'Proxy gateway must be empty for a direct profile';
    }
    if (($p['u_name'] ?? '') !== '' && rec_first('u_fwd_profile', "u_name={$p['u_name']}^sys_id!={$p['sys_id']}")) {
        $errors[] = 'A forwarding profile with this name already exists';
    }
    if ($errors) {
        throw new BusinessRuleException(implode("\n", $errors));
    }
}

/** Throws BusinessRuleException with every failed check. */
function validate_fwd_rule(array &$r, ?array $old): void
{
    $errors = [];
    $def = table_def('u_fwd_rule')['fields'];
    $r['u_value'] = mb_strtolower(trim($r['u_value'] ?? '')); // V1

    if (($r['number'] ?? '') !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}\z/', $r['number'])) {
        $errors[] = 'Number may only contain letters, digits, _ and - (1 to 40 characters)';
    }
    foreach ($def as $f => $fd) {
        if (!empty($fd['mandatory']) && trim($r[$f] ?? '') === '') {
            $errors[] = "{$fd['label']} is mandatory";
        }
    }
    if (($r['u_profile'] ?? '') !== '') {
        $prof = rec_get('u_fwd_profile', $r['u_profile']);
        if (!$prof || $prof['u_active'] !== 'true') {
            $errors[] = 'Forwarding rules need an active forwarding profile';
        }
    }
    $mt = $r['u_match_type'] ?? '';
    if ($mt !== '' && !isset($def['u_match_type']['choices'][$mt])) {
        $errors[] = "Invalid Match type '$mt'";
    } elseif ($mt !== '' && ($e = pac_validate_value($mt, $r['u_value']))) {
        $errors[] = $e;
    }
    validate_rule_dates($r, $old, $def, $errors);
    if ($errors) {
        throw new BusinessRuleException(implode("\n", $errors));
    }

    // V8 / V9: same destination in another live rule
    $live = 'u_stateINpending_approval,approved,deployed';
    foreach (rec_query('u_fwd_rule', "u_match_type={$r['u_match_type']}^u_value={$r['u_value']}^$live^sys_id!={$r['sys_id']}")[0] as $o) {
        if ($o['sys_id'] === ($r['u_replaces'] ?? '') || ($o['u_replaces'] ?? '') === $r['sys_id']) {
            continue; // the rule being replaced
        }
        throw new BusinessRuleException(($o['u_profile'] === $r['u_profile'] ? 'Duplicate of ' : 'Conflicts with ')
            . "{$o['number']} ({$o['u_match_type']} {$o['u_value']} in " . display_value('u_fwd_rule', 'u_profile', $o['u_profile']) . ')');
    }
}

function validate_proxy_profile(array $p): void
{
    foreach (['"', "'", '\\', "\r", "\n"] as $c) {
        if (str_contains($p['u_proxy_chain'], $c)) {
            throw new BusinessRuleException('Proxy chain must not contain quotes, backslashes or line breaks');
        }
    }
    if (!preg_match('/^(PROXY|HTTPS|SOCKS5?|DIRECT)\b/', trim($p['u_proxy_chain']))) {
        throw new BusinessRuleException('Proxy chain must start with PROXY, HTTPS, SOCKS or DIRECT');
    }
}
