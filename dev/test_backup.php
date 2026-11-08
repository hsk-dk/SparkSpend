<?php
/**
 * Backup: consistent snapshot of live WAL databases, verification, restore, pruning.
 *
 * Run:  php dev/test_backup.php
 */
require __DIR__ . '/../includes/Backup.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
function throws(callable $f): ?string {
    try { $f(); return null; } catch (\Throwable $e) { return $e->getMessage(); }
}
function rrmdir(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) { if ($f === '.' || $f === '..') continue; $p = "$d/$f"; is_dir($p) ? rrmdir($p) : @unlink($p); }
    @rmdir($d);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup_test_' . getmypid();
rrmdir($base); mkdir($base, 0700, true);
$live = "$base/live"; $store = "$base/store"; mkdir($live); mkdir($store);

// A live database in WAL mode with an open connection and rows that are still only in the -wal file.
function makeDb(string $path, int $rows): PDO {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA wal_autocheckpoint=0');                       // keep new rows in the -wal file
    $db->exec('CREATE TABLE IF NOT EXISTS charges (id INTEGER PRIMARY KEY, kwh REAL)');
    $db->exec('CREATE TABLE IF NOT EXISTS _migrations (id INTEGER PRIMARY KEY, filename TEXT)');
    $db->exec("INSERT INTO _migrations (filename) VALUES ('001_initial.php')");
    $st = $db->prepare('INSERT INTO charges (kwh) VALUES (?)');
    for ($i = 0; $i < $rows; $i++) $st->execute([$i * 1.5]);
    return $db;
}
$c = makeDb("$live/charging_data.db", 200);
$p = makeDb("$live/powerlog_data.db", 500);
$sources = ['charging_data' => "$live/charging_data.db", 'powerlog_data' => "$live/powerlog_data.db"];

echo "create\n";
check('the rows exist only in the -wal file before the backup', is_file("$live/charging_data.db-wal") && filesize("$live/charging_data.db-wal") > 0);
$t0 = 1700000000;
$dir = Backup::create($sources, $store, $t0);
check('backup directory is named from the UTC time', basename($dir) === '20231114-221320', basename($dir));
check('manifest.json and one .db.gz per database', is_file("$dir/manifest.json") && is_file("$dir/charging_data.db.gz") && is_file("$dir/powerlog_data.db.gz"));
check('no raw .db or .partial left behind', !glob("$dir/*.db") && !glob("$store/*.partial"));
$m = json_decode(file_get_contents("$dir/manifest.json"), true);
check('manifest records integrity and migrations', $m['databases']['charging_data']['integrity'] === 'ok' && $m['databases']['charging_data']['migrations'] === 1);
check('manifest checksum is the checksum of the stored file', $m['databases']['powerlog_data']['sha256'] === hash_file('sha256', "$dir/powerlog_data.db.gz"));
check('a second backup in the same second is refused, not overwritten', throws(fn() => Backup::create($sources, $store, $t0)) !== null);
check('a missing source database fails without leaving a .partial', throws(fn() => Backup::create(['x' => "$live/nope.db"], $store, $t0 + 5)) !== null && !glob("$store/*.partial"));
check('an invalid database name is refused', throws(fn() => Backup::create(['../evil' => "$live/charging_data.db"], $store, $t0 + 6)) !== null);

echo "verify\n";
check('a good backup verifies', Backup::verify($dir) === []);

echo "restore (the snapshot holds the rows that were only in the WAL)\n";
$out = "$base/restored";
$log = Backup::restore($dir, ['charging_data' => "$out/charging_data.db", 'powerlog_data' => "$out/powerlog_data.db"], false);
$r = new PDO('sqlite:' . "$out/charging_data.db");
check('restored charging database has all 200 rows', (int) $r->query('SELECT COUNT(*) FROM charges')->fetchColumn() === 200);
$r2 = new PDO('sqlite:' . "$out/powerlog_data.db");
check('restored powerlog database has all 500 rows', (int) $r2->query('SELECT COUNT(*) FROM charges')->fetchColumn() === 500);
check('restore reports one line per database', count($log) === 2);
$r = $r2 = null;

echo "restore over an existing database\n";
$msg = throws(fn() => Backup::restore($dir, ['charging_data' => "$out/charging_data.db", 'powerlog_data' => "$out/powerlog_data.db"], false));
check('refuses to overwrite without --force', $msg !== null && str_contains($msg, '--force'), (string) $msg);
file_put_contents("$out/charging_data.db-wal", 'stale wal that must not be replayed');
$log = Backup::restore($dir, ['charging_data' => "$out/charging_data.db", 'powerlog_data' => "$out/powerlog_data.db"], true, $t0 + 60);
check('the old database is moved aside, not deleted', is_file("$out/charging_data.db.before-restore-20231114-221420"));
check('a stale -wal is moved away so it cannot be replayed', !is_file("$out/charging_data.db-wal") && is_file("$out/charging_data.db-wal.before-restore-20231114-221420"));
check('the restored database is intact after replacement', (new PDO('sqlite:' . "$out/charging_data.db"))->query('PRAGMA integrity_check')->fetchColumn() === 'ok');

echo "damaged and hostile backups\n";
$bad = Backup::create($sources, $store, $t0 + 100);
$bytes = file_get_contents("$bad/powerlog_data.db.gz");
$bytes[(int) (strlen($bytes) / 2)] = $bytes[(int) (strlen($bytes) / 2)] === "\x00" ? "\x01" : "\x00";
file_put_contents("$bad/powerlog_data.db.gz", $bytes);
$problems = Backup::verify($bad);
check('a changed byte is caught by the checksum', count($problems) === 1 && str_contains($problems[0], 'checksum'), implode('|', $problems));
check('restore refuses a damaged backup and touches nothing', throws(fn() => Backup::restore($bad, ['charging_data' => "$base/x1.db", 'powerlog_data' => "$base/x2.db"], false)) !== null && !is_file("$base/x1.db"));

$evil = Backup::create($sources, $store, $t0 + 200);
$mj = json_decode(file_get_contents("$evil/manifest.json"), true);
$mj['databases']['charging_data']['file'] = '../../../etc/passwd';
file_put_contents("$evil/manifest.json", json_encode($mj));
check('a manifest pointing outside the backup directory is rejected', Backup::verify($evil) !== []);
$mj = json_decode(file_get_contents("$evil/manifest.json"), true);
$mj['databases'] = ['../x' => ['file' => 'a.gz', 'sha256' => 'x']];
file_put_contents("$evil/manifest.json", json_encode($mj));
check('a manifest with a path-like database name is rejected', Backup::verify($evil) !== []);

mkdir("$store/20250101-000000");
check('a directory without manifest.json does not verify', Backup::verify("$store/20250101-000000") !== []);
check('a directory without manifest.json is not listed as a backup', !in_array("$store/20250101-000000", Backup::list($store), true));
rmdir("$store/20250101-000000");

echo "pruning\n";
$prune = "$base/prune"; mkdir($prune);
// 3 backups in March, 3 in April, 4 days in May. keep 2 daily + 1 month.
$stamps = ['20260301-010000', '20260315-010000', '20260330-010000', '20260401-010000', '20260415-010000', '20260430-010000',
           '20260501-010000', '20260502-010000', '20260503-010000', '20260504-010000'];
foreach ($stamps as $s) { mkdir("$prune/$s"); file_put_contents("$prune/$s/manifest.json", '{}'); }
mkdir("$prune/not-a-backup"); file_put_contents("$prune/not-a-backup/keep.txt", 'x');
$deleted = Backup::prune($prune, 2, 2);
$left = array_map('basename', Backup::list($prune));
check('keeps the newest 2 and the newest of the last 2 months (May, April)', $left === ['20260430-010000', '20260503-010000', '20260504-010000'], implode(',', $left));
check('older months are removed', count($deleted) === 7, (string) count($deleted));
check('a directory that is not named like a backup is never touched', is_file("$prune/not-a-backup/keep.txt"));
check('prune never deletes the newest backup, even with keepDaily 0', (function () use ($prune) { Backup::prune($prune, 0, 0); return in_array('20260504-010000', array_map('basename', Backup::list($prune)), true); })());
check('newest() returns the latest backup', basename(Backup::newest($prune)['dir']) === '20260504-010000');
check('newest() on an empty store is null', Backup::newest($store . '/none') === null);

$c = $p = null;
rrmdir($base);
echo "\n" . ($fails === 0 ? "All $n checks passed.\n" : "$fails of $n checks FAILED.\n");
exit($fails === 0 ? 0 : 1);
