<?php
/**
 * Tests for includes/ChargeOptimizer.php (cheapest-hours placement of home charges).
 *   Run: php dev/test_charge_optimizer.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
require __DIR__ . '/../includes/configuration.php';
date_default_timezone_set('Europe/Copenhagen');

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
function close(float $a, float $b, float $eps = 1e-6): bool { return abs($a - $b) <= $eps; }

// Prices for 2026-03-10 and 2026-03-11 (CET, no DST change): evening 3.0, day 2.0, night 1.0
$prices = [];
foreach (['2026-03-10', '2026-03-11'] as $d) {
    for ($h = 0; $h < 24; $h++) {
        $prices[sprintf('%s %02d', $d, $h)] = $h < 6 ? 1.0 : ($h >= 17 && $h < 22 ? 3.0 : 2.0);
    }
}
$key = fn(string $d, int $h) => sprintf('%s %02d', $d, $h);

echo "optimizeCharge()\n";
// 3 evening hours, 7 kWh each, charger 7.4 kW; window runs through the night
$actual = [$key('2026-03-10', 18) => 7.0, $key('2026-03-10', 19) => 7.0, $key('2026-03-10', 20) => 7.0];
$window = ChargeOptimizer::hourKeys(strtotime('2026-03-10 17:30'), strtotime('2026-03-11 05:30'), 'Europe/Copenhagen');
$r = ChargeOptimizer::optimizeCharge($window, $actual, $prices, 7.4);
check('actual = kWh x evening price', close($r['actual'], 21 * 3.0));
check('cheapest = the same kWh in night hours at 1.0 (3 x 7 kWh)', close($r['best'], 21 * 1.0), json_encode($r));
check('saving = actual - best', close($r['saving'], 42.0));
check('best hours are night hours', count(array_filter(array_keys($r['best_hours']), fn($k) => (int) substr($k, 11) < 6)) === 3);

$r = ChargeOptimizer::optimizeCharge($window, [$key('2026-03-11', 1) => 14.0], $prices, 7.4);
check('a charge already in the cheapest hours saves nothing', $r['saving'] === 0.0 && close($r['best'], $r['actual']));

// 30 kWh at max 7.4 kW needs 5 hours: 5 cheapest hours (all 1.0 in the night, only 1 night hour in a short window)
$short = ChargeOptimizer::hourKeys(strtotime('2026-03-10 23:00'), strtotime('2026-03-11 03:00'), 'Europe/Copenhagen');   // 23,0,1,2
$r = ChargeOptimizer::optimizeCharge($short, [$key('2026-03-10', 18) => 30.0], $prices, 7.4);
check('the charger rate caps kWh per hour (30 kWh over a window of actual 18:00 + 4 night hours)', $r !== null && close(array_sum($r['best_hours']), 30.0) && max($r['best_hours']) <= 7.4 + 1e-9, json_encode($r));
$r = ChargeOptimizer::optimizeCharge([$key('2026-03-10', 18)], [$key('2026-03-10', 18) => 30.0], $prices, 7.4);
check('window too small for the rate -> keeps the actual placement', $r['saving'] === 0.0 && $r['best_hours'] === [$key('2026-03-10', 18) => 30.0]);

check('an actual hour without a price cannot be valued -> null', ChargeOptimizer::optimizeCharge($window, ['2026-03-12 03' => 5.0], $prices, 7.4) === null);
check('no energy -> null', ChargeOptimizer::optimizeCharge($window, [], $prices, 7.4) === null);
$partial = $prices; unset($partial[$key('2026-03-11', 2)]);
$r = ChargeOptimizer::optimizeCharge($window, $actual, $partial, 7.4);
check('window hours without a price are not used', $r !== null && !isset($r['best_hours'][$key('2026-03-11', 2)]));

echo "hourKeys() / summarizeHours()\n";
check('hourKeys covers the partial first and last hour', ChargeOptimizer::hourKeys(strtotime('2026-03-10 17:30'), strtotime('2026-03-10 19:10'), 'Europe/Copenhagen') === [$key('2026-03-10', 17), $key('2026-03-10', 18), $key('2026-03-10', 19)]);
$dst = ChargeOptimizer::hourKeys(strtotime('2026-10-25 00:00'), strtotime('2026-10-25 06:00'), 'Europe/Copenhagen');   // 25-hour day: 02 occurs twice
check('autumn DST: the repeated local hour is listed once', count($dst) === count(array_unique($dst)) && in_array('2026-10-25 02', $dst, true));
$sum = ChargeOptimizer::summarizeHours([$key('2026-03-11', 1) => 7.0, $key('2026-03-11', 2) => 7.0, $key('2026-03-11', 4) => 3.0]);
check('consecutive hours are merged', $sum === [['date' => '2026-03-11', 'from' => 1, 'to' => 3, 'kwh' => 14.0], ['date' => '2026-03-11', 'from' => 4, 'to' => 5, 'kwh' => 3.0]], json_encode($sum));

echo "analyze() on a database\n";
$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
MigrationRunner::run($db, dirname(__DIR__) . '/migrations/charges');
$ins = $db->prepare("INSERT INTO charges (id, vehicleId, cablePluggedInAt, startedAt, stoppedAt, consumedKwh, cost, state) VALUES (?,?,?,?,?,?,?, 'completed')");
// evening charge (UTC timestamps: 18:00-21:00 CET = 17:00-20:00Z), plugged in 17:30 CET
$ins->execute([1, 1, '2026-03-10T16:30:00Z', '2026-03-10T17:00:00Z', '2026-03-10T20:00:00Z', 21.0, 60.0]);
// night charge: already cheap
$ins->execute([2, 1, '2026-03-10T23:50:00Z', '2026-03-11T00:00:00Z', '2026-03-11T02:00:00Z', 14.0, 20.0]);
// not valued: hours without prices (a different day)
$ins->execute([3, 1, '2026-02-01T16:30:00Z', '2026-02-01T17:00:00Z', '2026-02-01T20:00:00Z', 10.0, 30.0]);
// ended after "now": skipped
$ins->execute([4, 1, '2026-03-11T08:50:00Z', '2026-03-11T09:00:00Z', '2026-03-11T12:00:00Z', 10.0, 30.0]);
$now = strtotime('2026-03-11 10:00:00 Europe/Copenhagen');
$res = ChargeOptimizer::analyze($db, '2026-01-01', '2026-03-31', $prices, ['flexHours' => 12, 'maxKw' => 7.4, 'tz' => 'Europe/Copenhagen', 'now' => $now]);
check('two charges are valued, two skipped (no prices / not finished)', $res['totals']['charges'] === 2 && $res['totals']['skipped'] === 2, json_encode($res['totals']));
$c1 = $res['charges'][0] ?? [];
check('evening charge: actual 63, cheapest 21, saving 42', ($c1['id'] ?? 0) === 1 && close($c1['actual'], 63.0, 0.02) && close($c1['best'], 21.0, 0.02) && close($c1['saving'], 42.0, 0.02), json_encode($c1));
check('the night charge saves nothing', ($res['charges'][1]['saving'] ?? -1) == 0);
check('totals add up (saving 42 of 77 = 54.5 %)', close($res['totals']['saving'], 42.0, 0.05) && $res['totals']['saving_pct'] > 54 && $res['totals']['saving_pct'] < 55, json_encode($res['totals']));
check('monthly summary for 2026-03', count($res['months']) === 1 && $res['months'][0]['month'] === '2026-03' && $res['months'][0]['charges'] === 2);
check('parameters are reported back', $res['params']['flex_hours'] === 12.0 && $res['params']['max_kw'] === 7.4);
$res0 = ChargeOptimizer::analyze($db, '2026-01-01', '2026-03-31', $prices, ['flexHours' => 0, 'maxKw' => 7.4, 'tz' => 'Europe/Copenhagen', 'now' => $now]);
check('with no flexibility (0 h) the saving is much smaller', $res0['totals']['saving'] < $res['totals']['saving'] / 2, json_encode($res0['totals']));

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
