<?php
/**
 * The EV card's month-end cost, and what the electricity bill takes from it.
 *  - the rest of the month is priced at the home price of the LAST 8 DAYS, not the month's average, so a price that
 *    fell during the month (Nov 1-11 about 2.4 kr/kWh, Nov 13-20 about 1.4) is not carried forward at the old level;
 *  - without enough recent kWh to trust, or with a flat price, nothing changes (plain run-rate);
 *  - away charging stays on the EV card but is not on "Forventet elregning" (it is paid to the charging network).
 * Run:  php dev/test_dashboard_ev.php
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
/** @param array<int,array{0:string,1:float,2:float}> $home [day, kWh, kr]   @param array<int,array{0:string,1:float,2:float}> $away */
function ev(array $home, array $away = []): array {
    $c = freshDb('charges');
    $c->exec("INSERT INTO vehicles (id, vehicleName) VALUES (1, 'Enyaq')");
    $i = 0;
    foreach ($home as [$day, $kwh, $kr]) {
        $c->prepare("INSERT INTO charges (id, vehicleId, startedAt, stoppedAt, consumedKwh, cost, state, pairingSource) VALUES (?, 1, ?, ?, ?, ?, 'completed', 'matched')")
          ->execute([++$i, "{$day}T10:00:00Z", "{$day}T11:00:00Z", $kwh, $kr]);
    }
    foreach ($away as [$day, $kwh, $kr]) {
        $c->prepare("INSERT INTO ext_charges (vehicleId, providerId, kwh, pris, datetime) VALUES (1, 1, ?, ?, ?)")->execute([$kwh, $kr, "{$day} 12:00:00"]);
    }
    $now = (new DateTimeImmutable('2026-11-20 12:00:00', new DateTimeZone('Europe/Copenhagen')))->getTimestamp();
    return DashboardSummary::build($c, freshDb('powerlog'), $now)['ev'];
}
$naive = fn(array $ev) => $ev['home_cost'] / (19 + 0.5) * 30;    // Nov 20 12:00 = 19.5 days elapsed, 30 days in the month

echo "price fell during the month\n";
$ev = ev([['2026-11-02', 40, 96], ['2026-11-03', 40, 96], ['2026-11-04', 40, 96], ['2026-11-05', 40, 96],
          ['2026-11-15', 30, 36], ['2026-11-17', 30, 36], ['2026-11-19', 30, 36]],
         [['2026-11-10', 20, 80]]);
check('recent home price is the last 8 days (36 kr / 30 kWh = 1.2)', abs(($ev['recent_home_cpkwh'] ?? 0) - 1.2) < 0.001, json_encode($ev['recent_home_cpkwh'] ?? null));
check('the month\'s average is higher, as in the screenshot', $ev['home_cpkwh'] > 1.6, (string) $ev['home_cpkwh']);
check('home projection is below the plain run-rate (the old days were dearer)', $ev['home_projected_cost'] < $naive($ev) - 20, $ev['home_projected_cost'] . ' vs ' . round($naive($ev)));
check('and not below what is already spent', $ev['home_projected_cost'] > $ev['home_cost']);
check('the card still includes away charging: total - home >= away cost so far', $ev['projected_cost'] - $ev['home_projected_cost'] >= $ev['ext_cost'] - 1, json_encode([$ev['projected_cost'], $ev['home_projected_cost'], $ev['ext_cost']]));

echo "\nflat price, or too little recent charging: nothing changes\n";
$ev = ev([['2026-11-02', 40, 60], ['2026-11-05', 40, 60], ['2026-11-15', 30, 45], ['2026-11-17', 30, 45]]);
check('flat 1.5 kr/kWh: home projection is the plain run-rate', abs($ev['home_projected_cost'] - $naive($ev)) <= 1.0, $ev['home_projected_cost'] . ' vs ' . round($naive($ev), 1));
$ev = ev([['2026-11-02', 40, 96], ['2026-11-05', 40, 96], ['2026-11-12', 10, 12]]);
check('under 20 kWh in the last 8 days: no recent price is claimed', $ev['recent_home_cpkwh'] === null, json_encode($ev['recent_home_cpkwh']));
check('...and the home projection is the plain run-rate', abs($ev['home_projected_cost'] - $naive($ev)) <= 1.0, $ev['home_projected_cost'] . ' vs ' . round($naive($ev), 1));

echo "\nno charging at all\n";
$ev = ev([]);
check('no figures are made up', ($ev['home_projected_cost'] ?? 0) == 0 && $ev['recent_home_cpkwh'] === null, json_encode([$ev['home_projected_cost'], $ev['recent_home_cpkwh']]));

echo "\nThe bill takes home charging only\n";
$src = file_get_contents(__DIR__ . '/../includes/DashboardSummary.php');
check('evMtdCost is the home cost', (bool) preg_match('/\'evMtdCost\'\s*=>\s*\$_ev\[\'home_cost\'\]/', $src));
check('evProjCost is the home projection', (bool) preg_match('/\'evProjCost\'\s*=>\s*\$_ev\[\'home_projected_cost\'\]/', $src));

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
