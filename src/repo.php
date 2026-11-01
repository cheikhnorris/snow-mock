<?php
// Record access with business rules and field-level audit (GlideRecord equivalent).

function rec_get(string $table, string $sysId): ?array
{
    table_def($table);
    $st = db()->prepare('SELECT * FROM ' . qi($table) . ' WHERE sys_id = ?');
    $st->execute([$sysId]);
    return $st->fetch() ?: null;
}

/** @return array{0: array, 1: int} rows and total count */
function rec_query(string $table, string $encoded = '', ?int $limit = null, int $offset = 0): array
{
    [$where, $params, $order] = build_query($table, $encoded);
    $sql = ' FROM ' . qi($table) . ($where ? " WHERE $where" : '');
    $st = db()->prepare('SELECT COUNT(*) AS n' . $sql);
    $st->execute($params);
    $total = (int)$st->fetch()['n'];
    $sql = 'SELECT *' . $sql . ($order ? " ORDER BY $order" : ' ORDER BY rowid');
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return [$st->fetchAll(), $total];
}

function rec_first(string $table, string $encoded): ?array
{
    return rec_query($table, $encoded, 1)[0][0] ?? null;
}

function next_number(string $prefix): string
{
    $pdo = db();
    $pdo->prepare('INSERT OR IGNORE INTO sys_number (prefix, next) VALUES (?, 1001)')->execute([$prefix]);
    $st = $pdo->prepare('SELECT next FROM sys_number WHERE prefix = ?');
    $st->execute([$prefix]);
    $n = (int)$st->fetch()['next'];
    $pdo->prepare('UPDATE sys_number SET next = next + 1 WHERE prefix = ?')->execute([$prefix]);
    return $prefix . str_pad((string)$n, 7, '0', STR_PAD_LEFT);
}

function clean_input(string $table, array $data): array
{
    $def = table_def($table)['fields'];
    $out = [];
    foreach ($data as $k => $v) {
        if (!isset($def[$k])) {
            continue; // unknown and sys_* fields are ignored, as ServiceNow does
        }
        if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        }
        $v = $v === null ? '' : (string)$v;
        $type = $def[$k]['type'];
        if ($type === 'datetime' && $v !== '') {
            $v = normalize_datetime($v);
        }
        if ($type === 'password' && $v !== '' && !str_starts_with($v, '$2y$')) {
            $v = password_hash($v, PASSWORD_BCRYPT);
        }
        $out[$k] = $v;
    }
    return $out;
}

function normalize_datetime(string $v): string
{
    $v = trim(str_replace('T', ' ', $v));
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) {
        $v .= ':00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $v .= ' 00:00:00';
    }
    return $v;
}

function rec_insert(string $table, array $data, string $user, array $opt = []): array
{
    $def = table_def($table);
    $rec = array_fill_keys(columns($table), '');
    foreach ($def['fields'] as $f => $fd) {
        if (isset($fd['default'])) {
            $rec[$f] = $fd['default'];
        }
    }
    $rec = array_merge($rec, clean_input($table, $data));
    if (!empty($def['prefix']) && $rec['number'] === '') {
        $rec['number'] = next_number($def['prefix']);
    }
    $now = now_utc();
    $rec['sys_id'] = $opt['sys_id'] ?? new_sys_id();
    $rec['sys_created_on'] = $rec['sys_updated_on'] = $opt['created_on'] ?? $now;
    $rec['sys_created_by'] = $rec['sys_updated_by'] = $user;
    $rec['sys_mod_count'] = '0';

    if (empty($opt['skip_br'])) {
        br_before($table, 'insert', $rec, null, $user);
    }
    $cols = array_keys($rec);
    db()->prepare('INSERT INTO ' . qi($table) . ' (' . implode(',', array_map('qi', $cols)) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($rec));
    audit($table, $rec['sys_id'], 'record', '', 'created', $user);
    if (empty($opt['skip_br'])) {
        br_after($table, 'insert', $rec, null, $user);
    }
    return $rec;
}

function rec_update(string $table, string $sysId, array $changes, string $user, array $opt = []): array
{
    $old = rec_get($table, $sysId);
    if (!$old) {
        throw new NotFoundException("No record found in $table with sys_id $sysId");
    }
    $new = array_merge($old, clean_input($table, $changes));
    if (empty($opt['skip_br'])) {
        br_before($table, 'update', $new, $old, $user);
    }
    $diff = [];
    foreach (table_def($table)['fields'] as $f => $_) {
        if (($old[$f] ?? '') !== ($new[$f] ?? '')) {
            $diff[$f] = $new[$f];
        }
    }
    if (!$diff) {
        return $old;
    }
    $new['sys_updated_on'] = now_utc();
    $new['sys_updated_by'] = $user;
    $new['sys_mod_count'] = (string)((int)$old['sys_mod_count'] + 1);
    $set = $diff + ['sys_updated_on' => $new['sys_updated_on'], 'sys_updated_by' => $user, 'sys_mod_count' => $new['sys_mod_count']];
    db()->prepare('UPDATE ' . qi($table) . ' SET ' . implode(', ', array_map(fn($c) => qi($c) . ' = ?', array_keys($set)))
        . ' WHERE sys_id = ?')->execute([...array_values($set), $sysId]);
    foreach ($diff as $f => $v) {
        if (field_def($table, $f)['type'] !== 'password') {
            audit($table, $sysId, $f, $old[$f] ?? '', $v, $user);
        }
    }
    if (empty($opt['skip_br'])) {
        br_after($table, 'update', $new, $old, $user);
    }
    return $new;
}

function rec_delete(string $table, string $sysId, string $user): void
{
    if (!rec_get($table, $sysId)) {
        throw new NotFoundException("No record found in $table with sys_id $sysId");
    }
    db()->prepare('DELETE FROM ' . qi($table) . ' WHERE sys_id = ?')->execute([$sysId]);
    audit($table, $sysId, 'record', '', 'deleted', $user);
}

function audit(string $table, string $sysId, string $field, string $old, string $new, string $user): void
{
    if ($table === 'sys_audit') {
        return;
    }
    $cols = ['sys_id', 'sys_created_on', 'sys_created_by', 'sys_updated_on', 'sys_updated_by', 'sys_mod_count',
        'tablename', 'documentkey', 'fieldname', 'oldvalue', 'newvalue', 'user'];
    $now = now_utc();
    db()->prepare('INSERT INTO sys_audit (' . implode(',', array_map('qi', $cols)) . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([new_sys_id(), $now, $user, $now, $user, '0', $table, $sysId, $field, $old, $new, $user]);
}

function in_transaction(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn(); // nested call joins the outer transaction
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Display value of one field (reference -> display field of the target, choice -> label). */
function display_value(string $table, string $field, ?string $value): string
{
    static $cache = [];
    $value ??= '';
    if ($value === '') {
        return '';
    }
    $fd = field_def($table, $field);
    switch ($fd['type']) {
        case 'choice':
            return $fd['choices'][$value] ?? $value;
        case 'reference':
            $key = $fd['ref'] . ':' . $value;
            if (!array_key_exists($key, $cache)) {
                $r = rec_get($fd['ref'], $value);
                $cache[$key] = $r ? ($r[table_def($fd['ref'])['display']] ?? $value) : $value;
            }
            return $cache[$key];
        case 'glide_list':
            return implode(', ', array_map(function ($id) use ($fd, &$cache) {
                $key = $fd['ref'] . ':' . $id;
                if (!array_key_exists($key, $cache)) {
                    $r = rec_get($fd['ref'], $id);
                    $cache[$key] = $r ? $r[table_def($fd['ref'])['display']] : $id;
                }
                return $cache[$key];
            }, array_filter(explode(',', $value))));
        case 'password':
            return $value ? '********' : '';
        default:
            return $value;
    }
}
