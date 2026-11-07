<?php
/**
 * Freshness: the verdict per source and overall. Run:  php dev/test_freshness.php
 */
require __DIR__ . '/../includes/Freshness.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
$now = 1_800_000_000;
$c = fn(string $id, ?int $ageSec, array $x = []) => array_merge(
    ['id' => $id, 'label' => $id, 'last' => $ageSec === null ? null : $now - $ageSec, 'max_age' => 1800], $x);
$st = fn(array $r, string $id) => array_values(array_filter($r['checks'], fn($k) => $k['id'] === $id))[0]['status'];

echo "evaluate()\n";
$r = Freshness::evaluate([$c('a', 60), $c('b', 1800)], $now);
check('fresh data (and exactly at the limit) is ok', $r['status'] === 'ok' && $st($r, 'a') === 'ok' && $st($r, 'b') === 'ok');
$r = Freshness::evaluate([$c('a', 60), $c('b', 1801)], $now);
check('one second over the limit is stale and degrades the whole', $st($r, 'b') === 'stale' && $r['status'] === 'degraded');
$r = Freshness::evaluate([$c('a', null)], $now);
check('no data at all is unknown and degrades', $st($r, 'a') === 'unknown' && $r['status'] === 'degraded' && $r['checks'][0]['age_sec'] === null);
$r = Freshness::evaluate([$c('a', 10, ['error' => 'Monta 500'])], $now);
check('a reported error wins over fresh data', $st($r, 'a') === 'error' && $r['status'] === 'degraded' && $r['checks'][0]['error'] === 'Monta 500');
$r = Freshness::evaluate([$c('a', 10, ['error' => ''])], $now);
check('an empty error string is no error', $st($r, 'a') === 'ok');
$r = Freshness::evaluate([$c('a', 60), $c('opt', 999999, ['optional' => true])], $now);
check('an optional check that is stale is reported but does not degrade', $st($r, 'opt') === 'stale' && $r['status'] === 'ok');
$r = Freshness::evaluate([$c('a', -500)], $now);
check('a timestamp in the future counts as age 0 (clock skew), not negative', $r['checks'][0]['age_sec'] === 0 && $st($r, 'a') === 'ok');

echo "collect()\n";
$mk = function (): PDO {
    $d = new PDO('sqlite::memory:'); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $d->exec("CREATE TABLE sync_log (source TEXT PRIMARY KEY, last_sync_timestamp TEXT, last_sync_count INTEGER, updated_at TEXT, error_message TEXT)");
    return $d;
};
$ch = $mk(); $pl = $mk();
$pl->exec("CREATE TABLE powerloghus (logdate TEXT)");
$now = time();
$fmt = fn(int $ago) => date('Y-m-d H:i:s', $now - $ago);
$ch->exec("INSERT INTO sync_log (source, updated_at) VALUES ('monta', '" . $fmt(300) . "')");
$pl->exec("INSERT INTO sync_log (source, updated_at) VALUES ('heatpump', '" . $fmt(120) . "'), ('housepowerlog', '" . $fmt(120) . "')");
$pl->exec("INSERT INTO powerloghus VALUES ('" . $fmt(1800) . "')");
$dir = sys_get_temp_dir() . '/fresh_' . uniqid(); mkdir($dir);
touch($dir . '/sparkspend_eds_abc.dat');
$r = Freshness::collect($ch, $pl, $dir, $now);
check('everything fresh gives ok', $r['status'] === 'ok', json_encode(array_map(fn($k) => [$k['id'], $k['status']], $r['checks'])));
check('device_energy is left out until Home Assistant has sent something', !in_array('device_energy', array_column($r['checks'], 'id'), true));

$r = Freshness::collect($mk(), $pl, $dir, $now);
check('a source without any sync_log row is unknown', $st($r, 'monta') === 'unknown' && $r['status'] === 'degraded');

$pl->exec("UPDATE powerloghus SET logdate = '" . $fmt(5 * 3600) . "'");
$r = Freshness::collect($ch, $pl, $dir, $now);
check('a sync that runs but a meter that stopped is caught by meter_data', $st($r, 'hus') === 'ok' && $st($r, 'meter_data') === 'stale' && $r['status'] === 'degraded');

file_put_contents($dir . '/sparkspend_eds_backoff.dat', '5');
touch($dir . '/sparkspend_eds_abc.dat', $now - 5 * 3600);
$r = Freshness::collect($ch, $pl, $dir, $now);
check('the backoff marker is not mistaken for fresh prices', $st($r, 'prices') === 'stale');
check('an empty cache directory gives unknown prices', Freshness::newestPriceFile($dir . '/nonexistent') === null);

$ch->exec("UPDATE sync_log SET error_message = 'HTTP 401' WHERE source = 'monta'");
$r = Freshness::collect($ch, $pl, $dir, $now);
check('an error from the last run shows as error', $st($r, 'monta') === 'error');

$bare = new PDO('sqlite::memory:');
$r = Freshness::collect($bare, $bare, $dir, $now);
check('databases without any tables do not throw', $r['status'] === 'degraded');

array_map('unlink', glob($dir . '/*')); rmdir($dir);
echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
