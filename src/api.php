<?php
// REST Table API (/api/now/table) and OAuth token endpoint (/oauth_token.do).

function json_out(int $status, $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json;charset=UTF-8');
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function api_error(int $status, string $message, string $detail = ''): void
{
    json_out($status, ['error' => ['message' => $message, 'detail' => $detail], 'status' => 'failure']);
}

function request_headers(): array
{
    return array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
}

function check_password(string $userName, string $password): bool
{
    $u = user_by_name($userName);
    return $u && $u['active'] === 'true' && password_verify($password, $u['password']);
}

function api_authenticate(): ?string
{
    $auth = request_headers()['authorization'] ?? '';
    if (stripos($auth, 'Basic ') === 0) {
        [$user, $pass] = array_pad(explode(':', base64_decode(substr($auth, 6)), 2), 2, '');
        return check_password($user, $pass) ? $user : null;
    }
    if (stripos($auth, 'Bearer ') === 0) {
        $st = db()->prepare('SELECT user_name FROM oauth_token WHERE token = ? AND expires_at > ?');
        $st->execute([substr($auth, 7), time()]);
        return $st->fetch()['user_name'] ?? null;
    }
    return null;
}

function oauth_token_endpoint(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error(405, 'Method not allowed');
        return;
    }
    $clientId = $_POST['client_id'] ?? '';
    $secret = $_POST['client_secret'] ?? '';
    $auth = request_headers()['authorization'] ?? '';
    if ($clientId === '' && stripos($auth, 'Basic ') === 0) {
        [$clientId, $secret] = array_pad(explode(':', base64_decode(substr($auth, 6)), 2), 2, '');
    }
    $client = cfg()['oauth_clients'][$clientId] ?? null;
    if (!$client || !hash_equals($client['secret'], $secret)) {
        json_out(401, ['error_description' => 'access_denied', 'error' => 'server_error']);
        return;
    }
    $grant = $_POST['grant_type'] ?? '';
    if ($grant === 'client_credentials') {
        $user = $client['user'];
    } elseif ($grant === 'password' && check_password($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        $user = $_POST['username'];
    } else {
        json_out(401, ['error_description' => 'access_denied', 'error' => 'server_error']);
        return;
    }
    $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $ttl = cfg()['token_ttl'];
    db()->prepare('INSERT INTO oauth_token (token, user_name, expires_at) VALUES (?, ?, ?)')->execute([$token, $user, time() + $ttl]);
    db()->prepare('DELETE FROM oauth_token WHERE expires_at < ?')->execute([time()]);
    json_out(200, ['access_token' => $token, 'scope' => 'useraccount', 'token_type' => 'Bearer', 'expires_in' => $ttl - 1]);
}

/** Table-level ACLs. Field-level rules live in the business rules. */
function api_acl(string $op, string $table, string $user): bool
{
    if (is_admin($user)) {
        return true;
    }
    $pacTables = ['u_pac_rule', 'u_pac_region', 'u_pac_proxy_profile', 'u_pac_deployment', 'u_url_category', 'u_url_rule', 'u_fwd_profile', 'u_fwd_rule'];
    if (is_integration($user)) {
        return match ($op) {
            'read' => in_array($table, $pacTables, true),
            'write' => in_array($table, ['u_pac_rule', 'u_url_rule', 'u_fwd_rule'], true),
            'create' => $table === 'u_pac_deployment',
            default => false,
        };
    }
    if (is_pac_admin($user)) {
        return in_array($table, [...$pacTables, 'change_request', 'sc_req_item', 'sysapproval_approver'], true) && $op !== 'delete';
    }
    return $op === 'read' && in_array($table, $pacTables, true);
}

function api_table(string $rest, string $method): void
{
    $user = api_authenticate();
    if ($user === null) {
        header('WWW-Authenticate: Basic realm="Service-now"');
        api_error(401, 'User Not Authenticated', 'Required to provide Auth information');
        return;
    }
    $parts = array_values(array_filter(explode('/', $rest), 'strlen'));
    $table = $parts[0] ?? '';
    $sysId = $parts[1] ?? null;
    try {
        table_def($table);
    } catch (NotFoundException $e) {
        api_error(400, 'Invalid table ' . $table);
        return;
    }
    $op = match ($method) {
        'GET' => 'read',
        'POST' => 'create',
        'PUT', 'PATCH' => 'write',
        'DELETE' => 'delete',
        default => null,
    };
    if ($op === null || ($op === 'create' && $sysId) || (in_array($op, ['write', 'delete'], true) && !$sysId)) {
        api_error(405, 'Method not Supported', "$method is not supported on this resource");
        return;
    }
    if (!api_acl($op, $table, $user)) {
        api_error(403, 'Operation Failed', "ACL Exception $op Failed due to security constraints");
        return;
    }

    try {
        $opts = api_options();
        if ($op === 'read' && $sysId === null) {
            $limit = isset($_GET['sysparm_limit']) ? (int)$_GET['sysparm_limit'] : 10000;
            $offset = (int)($_GET['sysparm_offset'] ?? 0);
            [$rows, $total] = rec_query($table, $_GET['sysparm_query'] ?? '', $limit, $offset);
            json_out(200, ['result' => array_map(fn($r) => api_format($table, $r, $opts), $rows)], ['X-Total-Count' => $total]);
            return;
        }
        if ($op === 'read') {
            $rec = rec_get($table, $sysId) ?? throw new NotFoundException('Record doesn\'t exist or ACL restricts the record retrieval');
            json_out(200, ['result' => api_format($table, $rec, $opts)]);
            return;
        }
        if ($op === 'delete') {
            rec_delete($table, $sysId, $user);
            http_response_code(204);
            return;
        }
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) {
            api_error(400, 'Exception while reading request', 'Request body is not valid JSON');
            return;
        }
        $rec = $op === 'create'
            ? in_transaction(fn() => rec_insert($table, $body, $user))
            : in_transaction(fn() => rec_update($table, $sysId, $body, $user));
        json_out($op === 'create' ? 201 : 200, ['result' => api_format($table, $rec, $opts)]);
    } catch (NotFoundException $e) {
        api_error(404, 'No Record found', $e->getMessage());
    } catch (BusinessRuleException $e) {
        api_error(403, "Operation against file '$table' was aborted by Business Rule", $e->getMessage());
    }
}

function api_options(): array
{
    $fields = trim($_GET['sysparm_fields'] ?? '');
    return [
        'fields' => $fields === '' ? null : array_map('trim', explode(',', $fields)),
        'display' => strtolower($_GET['sysparm_display_value'] ?? 'false'),
        'exclude_ref' => strtolower($_GET['sysparm_exclude_reference_link'] ?? 'false') === 'true',
    ];
}

function api_format(string $table, array $rec, array $opts): array
{
    $base = (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : '') . '/api/now/table/';
    $out = [];
    foreach ($opts['fields'] ?? columns($table) as $f) {
        if (!array_key_exists($f, $rec)) {
            continue;
        }
        $fd = field_def($table, $f);
        $value = $fd['type'] === 'password' ? '' : (string)$rec[$f];
        $display = in_array($opts['display'], ['true', 'all'], true) ? display_value($table, $f, $value) : $value;
        if ($opts['display'] === 'all') {
            $out[$f] = ['display_value' => $display, 'value' => $value];
        } elseif ($fd['type'] === 'reference' && $value !== '' && !$opts['exclude_ref']) {
            $out[$f] = ['link' => $base . $fd['ref'] . '/' . $value, 'value' => $opts['display'] === 'true' ? $display : $value];
        } else {
            $out[$f] = $opts['display'] === 'true' ? $display : $value;
        }
    }
    return $out;
}
