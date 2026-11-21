<?php
/**
 * AnnualSummary: the per-year figures the stacked Årsrapport chart is built from.
 *  - EV home charging (what passes the house meter) is kept apart from charging away (which does not): home_kwh / home_cost
 *    next to the totals kwh / cost;
 *  - the heat pump and the house come from the meters' counter differences;
 *  - with prices (MOCK_EDS, offline) every year has a cost with the heat pump's, the rest's and the house's price.
 * Run:  php dev/test_annual_summary.php
 */
$cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_annual_test_' . bin2hex(random_bytes(4));
mkdir($cache);
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
putenv('MOCK_EDS=true');
putenv('ELSPOT_AREA=DK2');
putenv('ELSPOT_GLN=0000000000000');
putenv('CACHE_DIR=' . $cache);
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
function close(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) <= $eps; }

$c = freshDb('charges');
$c->exec("INSERT INTO vehicles (id, vehicleName) VALUES (1, 'Enyaq')");
$c->exec("INSERT INTO providers (providerName) VALUES ('Clever')");
$i = 0;
foreach ([['2024-06-10', 100, 150], ['2025-03-05', 200, 220], ['2025-07-01', 50, 60]] as [$day, $kwh, $kr]) {
    $c->prepare("INSERT INTO charges (id, vehicleId, startedAt, stoppedAt, consumedKwh, cost, state, pairingSource) VALUES (?, 1, ?, ?, ?, ?, 'completed', 'matched')")
      ->execute([++$i, "{$day}T10:00:00Z", "{$day}T12:00:00Z", $kwh, $kr]);
}
$c->prepare("INSERT INTO ext_charges (vehicleId, providerId, kwh, pris, datetime) VALUES (1, 1, 40, 160, ?)")->execute(['2025-05-05 12:00:00']);

// One reading a day, 1 kWh/day on the heat pump and 3 kWh/day on the house (the meters cap a day at a plausible figure, so no big jumps).
// The first reading has nothing to be compared with: 2024 (366 readings) holds 365 deltas, 2025 holds 365.
$p = freshDb('powerlog');
$hp = 0; $hus = 0;
for ($d = new DateTimeImmutable('2024-01-01 12:00:00'); $d <= new DateTimeImmutable('2025-12-31 12:00:00'); $d = $d->modify('+1 day')) {
    $hp += 1; $hus += 3;
    $p->prepare("INSERT INTO powerlogjord (logdate, kwh) VALUES (?, ?)")->execute([$d->format('Y-m-d H:i:s'), $hp]);
    $p->prepare("INSERT INTO powerloghus (logdate, kwh) VALUES (?, ?)")->execute([$d->format('Y-m-d H:i:s'), $hus]);
}

$r = AnnualSummary::build($c, $p);
$years = array_column($r['payload']['years'], null, 'year');

echo "EV: home charging apart from the total\n";
check('two years', array_map('intval', array_keys($years)) === [2024, 2025], json_encode(array_keys($years)));
check('2024: home 100 kWh / 150 kr, and the total is the same (nothing away)', $years['2024']['ev']['home_kwh'] == 100 && $years['2024']['ev']['home_cost'] == 150 && $years['2024']['ev']['kwh'] == 100 && $years['2024']['ev']['cost'] == 150, json_encode($years['2024']['ev']));
check('2025: home 250 kWh / 280 kr', $years['2025']['ev']['home_kwh'] == 250 && $years['2025']['ev']['home_cost'] == 280, json_encode($years['2025']['ev']));
check('2025: the total adds the 40 kWh / 160 kr charged away (290 kWh, 440 kr)', $years['2025']['ev']['kwh'] == 290 && $years['2025']['ev']['cost'] == 440, json_encode($years['2025']['ev']));

echo "\nthe meters\n";
check('heat pump per year (1 kWh a day: 365 and 365)', close($years['2024']['heatpump']['kwh'], 365) && close($years['2025']['heatpump']['kwh'], 365), json_encode([$years['2024']['heatpump'], $years['2025']['heatpump']]));
check('house per year (3 kWh a day: 1095 and 1095)', close($years['2024']['hus']['kwh'], 1095) && close($years['2025']['hus']['kwh'], 1095), json_encode([$years['2024']['hus'], $years['2025']['hus']]));

echo "\nthe cost of each year (offline prices)\n";
check('prices are complete', $r['complete'] === true);
foreach (['2024', '2025'] as $y) {
    $cost = $years[$y]['cost'] ?? null;
    check("$y: a cost with vp, rest and hus", is_array($cost) && isset($cost['vp'], $cost['rest'], $cost['hus']), json_encode($cost));
    if (!is_array($cost)) continue;
    check("$y: the rest is a price for kWh that exist (house - heat pump - home EV > 0), never negative", $cost['rest'] > 0 && $cost['vp'] > 0);
    check("$y: heat pump + rest never exceed the whole house's price (the EV is the difference)", $cost['vp'] + $cost['rest'] <= $cost['hus'] + 0.01, json_encode($cost));
}
$rest = fn($y) => max(0, $years[$y]['hus']['kwh'] - $years[$y]['heatpump']['kwh'] - $years[$y]['ev']['home_kwh']);
check('the rest in kWh the chart will show: 1095 - 365 - 100 = 630 (2024) and 1095 - 365 - 250 = 480 (2025)', close($rest('2024'), 630) && close($rest('2025'), 480), json_encode([$rest('2024'), $rest('2025')]));

echo "\nno house meter: no rest\n";
$p2 = freshDb('powerlog');
$p2->exec("DROP TABLE powerloghus");
$r2 = AnnualSummary::build($c, $p2);
$y2 = array_column($r2['payload']['years'], null, 'year');
check('hus is null and the cost has no rest', $y2['2025']['hus'] === null && (($y2['2025']['cost']['rest'] ?? null) === null), json_encode([$y2['2025']['hus'], $y2['2025']['cost'] ?? null]));

echo "\na year the heat pump meter does not cover (real data: house from 2021-03-09, heat pump from 2021-10-25)\n";
$p3 = freshDb('powerlog');
$h = 0; $k = 0;
for ($d = new DateTimeImmutable('2021-03-09 12:00:00'); $d <= new DateTimeImmutable('2022-12-31 12:00:00'); $d = $d->modify('+1 day')) {
    $h += 3;
    $p3->prepare("INSERT INTO powerloghus (logdate, kwh) VALUES (?, ?)")->execute([$d->format('Y-m-d H:i:s'), $h]);
    if ($d >= new DateTimeImmutable('2021-10-25 12:00:00')) { $k += 1; $p3->prepare("INSERT INTO powerlogjord (logdate, kwh) VALUES (?, ?)")->execute([$d->format('Y-m-d H:i:s'), $k]); }
}
$r3 = AnnualSummary::build($c, $p3);
$y3 = array_column($r3['payload']['years'], null, 'year');
check('2021: split_ok is false (the house meter starts before the heat pump meter)', ($y3['2021']['split_ok'] ?? null) === false, json_encode($y3['2021'] ?? null));
check('2022: split_ok is true (both meters cover the whole year)', ($y3['2022']['split_ok'] ?? null) === true);
check('the payload says where each meter starts', ($r3['payload']['meters']['hus_from'] ?? '') === '2021-03-09' && ($r3['payload']['meters']['hp_from'] ?? '') === '2021-10-25', json_encode($r3['payload']['meters'] ?? null));
check('the first set (both meters from 2024-01-01) is split_ok in every year', ($years['2024']['split_ok'] ?? null) === true && ($years['2025']['split_ok'] ?? null) === true);
check('no house meter: split_ok false (nothing to split)', ($y2['2025']['split_ok'] ?? null) === false);

foreach (glob($cache . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
@rmdir($cache);
echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
