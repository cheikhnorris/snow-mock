<?php

class NotFoundException extends RuntimeException {}
class BusinessRuleException extends RuntimeException {}
class AclException extends RuntimeException {}

function cfg(): array
{
    static $cfg = null;
    return $cfg ??= require __DIR__ . '/../config.php';
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function new_sys_id(): string
{
    return bin2hex(random_bytes(16));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $path = cfg()['db_path'];
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    $fresh = !file_exists($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    ensure_schema($pdo);
    if ($fresh) {
        require_once __DIR__ . '/seed.php';
        in_transaction('seed_demo_data');
    }
    return $pdo;
}

function qi(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ensure_schema(PDO $pdo): void
{
    foreach (array_keys(schema()) as $table) {
        $cols = columns($table);
        $defs = array_map(fn($c) => qi($c) . ($c === 'sys_id' ? ' TEXT PRIMARY KEY' : ' TEXT'), $cols);
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . qi($table) . ' (' . implode(', ', $defs) . ')');
        $existing = array_column($pdo->query('PRAGMA table_info(' . qi($table) . ')')->fetchAll(), 'name');
        foreach (array_diff($cols, $existing) as $missing) {
            $pdo->exec('ALTER TABLE ' . qi($table) . ' ADD COLUMN ' . qi($missing) . ' TEXT');
        }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS sys_number (prefix TEXT PRIMARY KEY, next INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_token (token TEXT PRIMARY KEY, user_name TEXT, expires_at INTEGER)');
}

function reset_database(): void
{
    $path = cfg()['db_path'];
    foreach ([$path, "$path-wal", "$path-shm"] as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
}
