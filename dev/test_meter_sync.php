<?php
/**
 * MeterSync: the MySQL -> SQLite copy of the heat pump and house meter, against an in-memory powerlog schema
 * and a fake MySQL fetcher. Run:  php dev/test_meter_sync.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';
require __DIR__ . '/../includes/autoload.php';
Config::load();

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
function powerlogDb(): PDO {
    $db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    ob_start(); MigrationRunner::run($db, __DIR__ . '/../migrations/powerlog'); ob_end_clean();
    return $db;
}
/** A fake MySQL: the hourly rows with logdate > $after, served in pages. Counts how often it is asked. */
function fakeSource(array $rows, int &$calls = null): callable {
    return function (?string $after, int $page, int $offset) use ($rows, &$calls): array {
        $calls++;
        $rows = array_values(array_filter($rows, fn($r) => $after === null || $r['logdate'] > $after));
        return array_slice($rows, $offset, $page);
    };
}
$hours = fn(int $n, string $from = '2026-01-01 00:00:00', float $kwh = 100.0) => array_map(
    fn($i) => ['logdate' => date('Y-m-d H:i:s', strtotime($from) + $i * 3600), 'kwh' => $kwh + $i], range(0, $n - 1));
$logged = [];
$log = function (string $m, string $l = 'INFO') use (&$logged) { $logged[] = "$l: $m"; };
$def = MeterSync::SOURCES['hus'];

echo "first sync and watermark\n";
$db = powerlogDb();
$stored = MeterSync::run($db, $def, fakeSource($hours(5)), $log);
check('the first run stores every hour', $stored === 5 && (int) $db->query("SELECT COUNT(*) FROM powerloghus")->fetchColumn() === 5);
$sl = $db->query("SELECT * FROM sync_log WHERE source = 'housepowerlog'")->fetch();
check('sync_log gets the watermark (newest hour), the count and a clean status', $sl['last_sync_timestamp'] === '2026-01-01 04:00:00' && (int) $sl['last_sync_count'] === 5 && $sl['error_message'] === null);
check('it logs what it did', (bool) array_filter($logged, fn($l) => str_contains($l, 'Stored 5 of 5')));

echo "incremental\n";
$calls = 0;
$stored = MeterSync::run($db, $def, fakeSource($hours(8), $calls), $log);
check('the next run asks only for what is after the watermark', $stored === 3 && $calls >= 1);
check('the table now has all 8 hours', (int) $db->query("SELECT COUNT(*) FROM powerloghus")->fetchColumn() === 8);
$before = $db->query("SELECT updated_at FROM sync_log WHERE source = 'housepowerlog'")->fetchColumn();
$logged = [];
$stored = MeterSync::run($db, $def, fakeSource($hours(8)), $log);
$sl = $db->query("SELECT * FROM sync_log WHERE source = 'housepowerlog'")->fetch();
check('a run with nothing new stores nothing, logs nothing, keeps the watermark', $stored === 0 && $logged === [] && $sl['last_sync_timestamp'] === '2026-01-01 07:00:00' && (int) $sl['last_sync_count'] === 0);
check('...but still records that the job ran', $sl['updated_at'] >= $before);

echo "idempotent and replace\n";
$db2 = powerlogDb();
MeterSync::run($db2, $def, fakeSource($hours(3)), $log);
$db2->exec("DELETE FROM sync_log");   // watermark lost: everything is fetched again
MeterSync::run($db2, $def, fakeSource($hours(3, '2026-01-01 00:00:00', 500.0)), $log);
check('fetching the same hours again replaces them (no duplicates, new value wins)', (int) $db2->query("SELECT COUNT(*) FROM powerloghus")->fetchColumn() === 3 && (float) $db2->query("SELECT kwh FROM powerloghus WHERE logdate = '2026-01-01 00:00:00'")->fetchColumn() === 500.0);

echo "the two sources\n";
$db3 = powerlogDb();
MeterSync::run($db3, MeterSync::SOURCES['heatpump'], fakeSource($hours(2)), $log);
check('the heat pump goes to powerlogjord and its own sync_log row', (int) $db3->query("SELECT COUNT(*) FROM powerlogjord")->fetchColumn() === 2 && (int) $db3->query("SELECT COUNT(*) FROM powerloghus")->fetchColumn() === 0 && $db3->query("SELECT COUNT(*) FROM sync_log WHERE source = 'heatpump'")->fetchColumn() == 1);
check('SOURCES: only the house meter is Wh-normalised', MeterSync::SOURCES['hus']['normaliseWh'] === true && MeterSync::SOURCES['heatpump']['normaliseWh'] === false);
check('normaliseKwh: a Wh value becomes kWh, a kWh value is left alone', MeterSync::normaliseKwh(123456.0) === 123.46 && MeterSync::normaliseKwh(54321.0) === 54321.0 && MeterSync::normaliseKwh(100000.0) === 100000.0);

echo "robustness\n";
$db4 = powerlogDb();
$stored = MeterSync::run($db4, $def, fakeSource([['logdate' => '2026-02-01 00:00:00', 'kwh' => 5], ['logdate' => null, 'kwh' => 1], ['logdate' => '2026-02-01 01:00:00', 'kwh' => null], ['logdate' => '2026-02-01 02:00:00', 'kwh' => '7.5']]), $log);
check('rows without logdate or kwh are skipped, numeric strings are accepted', $stored === 2 && (float) $db4->query("SELECT kwh FROM powerloghus WHERE logdate = '2026-02-01 02:00:00'")->fetchColumn() === 7.5);
$thrown = null;
try { MeterSync::run($db4, $def, function () { throw new RuntimeException('MySQL query failed: gone away'); }, $log); } catch (\Throwable $e) { $thrown = $e->getMessage(); }
check('a failing source throws (CronJob logs it and records the error) and changes nothing', $thrown === 'MySQL query failed: gone away' && (int) $db4->query("SELECT COUNT(*) FROM powerloghus")->fetchColumn() === 2);
$hit = false;
MeterSync::run($db4, $def, fakeSource($hours(1, '2026-03-01 00:00:00')), $log, function () use (&$hit) { $hit = true; });
check('afterStore (cache invalidation) runs when something was stored', $hit);
$hit = false;
MeterSync::run($db4, $def, fakeSource([]), $log, function () use (&$hit) { $hit = true; });
check('...and not when nothing was', !$hit);
$thrown = null;
try { MeterSync::store($db4, 'users; DROP TABLE x', [['logdate' => 'a', 'kwh' => 1]], $log); } catch (\InvalidArgumentException $e) { $thrown = true; }
check('store() refuses a table that is not one of the two meter tables', $thrown === true);

echo "paging and limits\n";
$logged = [];
$many = array_map(fn($i) => ['logdate' => sprintf('2026-04-01 00:%02d:%02d', intdiv($i, 60), $i % 60), 'kwh' => $i], range(0, 2499));
$calls = 0;
$all = MeterSync::collect(fakeSource($many, $calls), '2026-03-01 00:00:00', $log);
check('an incremental run pages through 1000 at a time (3 pages + the empty one)', count($all) === 2500 && $calls === 4);
$huge = array_map(fn($i) => ['logdate' => '2026-05-01 ' . sprintf('%02d:%02d:%02d', intdiv($i, 3600) % 24, intdiv($i, 60) % 60, $i % 60), 'kwh' => $i], range(0, 11999));
$all = MeterSync::collect(fakeSource($huge), '2026-03-01 00:00:00', $log);
check('an incremental run stops at 10,000 and says the rest follows', count($all) === 10000 && (bool) array_filter($logged, fn($l) => str_contains($l, 'Batch limit')));
check('a first sync uses big pages', ($c2 = (function () { $seen = null; MeterSync::collect(function ($a, $p, $o) use (&$seen) { $seen = $p; return []; }, null, fn() => null); return $seen; })()) === MeterSync::PAGE_FIRST, (string) $c2);

echo "mysqlFetcher\n";
$thrown = null;
try { MeterSync::mysqlFetcher(new PDO('sqlite::memory:'), 'powerlog`; DROP', false); } catch (\InvalidArgumentException $e) { $thrown = true; }
check('a MySQL table name with anything but letters, digits and _ is refused', $thrown === true);
check('a proper table name gives a fetcher', is_callable(MeterSync::mysqlFetcher(new PDO('sqlite::memory:'), 'powerloghus', true)));

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
