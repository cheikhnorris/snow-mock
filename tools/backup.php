<?php
// Usage: php tools/backup.php <dest_dir> [keep]
// Writes <dest_dir>/db/snow-<UTC timestamp>.sqlite (consistent snapshot, OAuth tokens removed)
// and <dest_dir>/tables/<table>.json (readable exports, password hashes removed; git history versions them).
// Keeps the newest <keep> snapshots (default 14).
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$dest = rtrim($argv[1] ?? '', '/\\');
$keep = max(1, (int)($argv[2] ?? 14));
if ($dest === '') {
    fwrite(STDERR, "usage: php tools/backup.php <dest_dir> [keep]\n");
    exit(2);
}
foreach (["$dest/db", "$dest/tables"] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        fwrite(STDERR, "cannot create $dir\n");
        exit(1);
    }
}

$stamp = gmdate('Ymd-His');
$snapshot = "$dest/db/snow-$stamp.sqlite";
db()->exec('VACUUM INTO ' . db()->quote($snapshot)); // safe while the server is running
$copy = new PDO('sqlite:' . $snapshot);
$copy->exec('DELETE FROM oauth_token');
$copy->exec('VACUUM');
$copy = null;

$counts = [];
foreach (array_keys(schema()) as $table) {
    $rows = rec_query($table, 'ORDERBYsys_created_on^ORDERBYsys_id')[0];
    foreach ($rows as &$r) {
        foreach (table_def($table)['fields'] as $f => $fd) {
            if ($fd['type'] === 'password') {
                $r[$f] = '';
            }
        }
    }
    unset($r);
    file_put_contents("$dest/tables/$table.json",
        json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $counts[] = "$table=" . count($rows);
}

$snapshots = glob("$dest/db/snow-*.sqlite");
sort($snapshots);
foreach (array_slice($snapshots, 0, max(0, count($snapshots) - $keep)) as $old) {
    unlink($old);
}

echo "snapshot " . basename($snapshot) . ' (' . round(filesize($snapshot) / 1024) . " KB)\n";
echo implode(' ', $counts) . "\n";
