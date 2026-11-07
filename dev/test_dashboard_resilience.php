<?php
/**
 * DashboardSummary when a data source is missing: the house meter table has not been synced yet. The summary
 * must still be built, without PHP warnings, and say "no house data" instead of inventing numbers.
 * (PHPStan flagged $husDailyMap as "might not be defined"; it is a false positive - the only read sits behind
 * `if ($husCurrentKwh !== null)`, which implies the try-block got past the assignment. The variable is
 * initialised anyway, and this test pins the behaviour so a refactor cannot make it real.)
 * Run:  php dev/test_dashboard_resilience.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';
require __DIR__ . '/../includes/autoload.php';
Config::load();
date_default_timezone_set('Europe/Copenhagen');

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
function freshDb(string $kind): PDO {
    $db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    ob_start(); MigrationRunner::run($db, __DIR__ . "/../migrations/$kind"); ob_end_clean();
    return $db;
}

$charges  = freshDb('charges');
$powerlog = freshDb('powerlog');
$today = date('Y-m-d');
$charges->exec("INSERT INTO vehicles (id, vehicleName) VALUES (1, 'Enyaq')");
$charges->prepare("INSERT INTO charges (id, vehicleId, startedAt, stoppedAt, consumedKwh, cost, state, pairingSource) VALUES (1, 1, ?, ?, 10, 20, 'completed', 'matched')")
        ->execute([gmdate('Y-m-d\TH:i:s\Z', strtotime('-2 hours')), gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 hour'))]);

$warnings = [];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings): bool {
    $warnings[] = basename($file) . ':' . $line . ' ' . $msg;
    return true;
});

echo "with every source present\n";
$full = DashboardSummary::build($charges, $powerlog);
check('builds without warnings', $warnings === [], implode(' | ', $warnings));
check('the payload has the three areas', isset($full['ev'], $full['heatpump']) && array_key_exists('hus', $full), implode(',', array_keys($full)));

echo "house meter table missing (not synced yet)\n";
$powerlog->exec("DROP TABLE powerloghus");
$warnings = [];
$partial = DashboardSummary::build($charges, $powerlog);
check('still builds', is_array($partial) && isset($partial['ev']));
check('no PHP warnings (no undefined variable is read when the house block fails)', $warnings === [], implode(' | ', $warnings));
check('the house block is empty, not made up', empty($partial['hus']) || ($partial['hus']['month_kwh'] ?? null) === null, json_encode($partial['hus'] ?? null));
check('the rest figure does not claim a number when there is no house meter', empty($partial['rest']) || ($partial['rest']['month_kwh'] ?? null) === null || $partial['rest']['month_kwh'] == 0, json_encode($partial['rest'] ?? null));

restore_error_handler();
echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
