<?php
/** Unit tests for includes/EfficiencyCalc.php.  Run: php dev/test_efficiency.php */
require __DIR__ . '/../includes/EfficiencyCalc.php';

$fails = 0; $n = 0;
function check(string $name, $actual, $expected, float $eps = 1e-9): void {
    global $fails, $n; $n++;
    $ok = $actual === $expected || (is_numeric($actual) && is_numeric($expected) && !is_string($actual) && abs($actual - $expected) <= $eps);
    if (!$ok && is_array($actual) && is_array($expected) && array_keys($actual) === array_keys($expected)) {
        $ok = true; foreach ($actual as $k => $v) { if (is_array($v) || $v !== $expected[$k] && !(is_numeric($v) && is_numeric($expected[$k]) && abs($v - $expected[$k]) <= $eps)) { $ok = false; break; } }
    }
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name\n       expected " . json_encode($expected) . "\n       got      " . json_encode($actual) . "\n";
}
$rd = fn(int $ts, float $odo) => ['ts' => $ts, 'odometer' => $odo];
$ch = fn(int $ts, float $kwh, ?float $cost = null) => ['ts' => $ts, 'kwh' => $kwh, 'cost' => $cost];
$H = 3600; $D = 86400; $T0 = 1_780_000_000;

echo "intervals()\n";
$r = EfficiencyCalc::intervals([$rd($T0, 1000), $rd($T0 + $D, 1100), $rd($T0 + 2 * $D, 1250)],
                               [$ch($T0 + $H, 20, 40.0), $ch($T0 + $D + $H, 30, 60.0)]);
check('two intervals', count($r['intervals']), 2);
check('interval 1: 100 km, the charge after reading 1 refills it', [$r['intervals'][0]['km'], $r['intervals'][0]['kwh'], $r['intervals'][0]['cost']], [100.0, 20.0, 40.0]);
check('interval 2: 150 km, 30 kWh', [$r['intervals'][1]['km'], $r['intervals'][1]['kwh']], [150.0, 30.0]);

$r = EfficiencyCalc::intervals([$rd($T0, 0), $rd($T0 + $D, 10)], [$ch($T0, 5), $ch($T0 + $D, 7), $ch($T0 + $D + 1, 9)]);
check('a charge exactly at the first reading belongs to the previous interval; exactly at the second is included', $r['intervals'][0]['kwh'], 7.0);
$r = EfficiencyCalc::intervals([$rd($T0 + $D, 10), $rd($T0 + 2 * $D, 40)], [$ch($T0, 99), $ch($T0 + 3 * $D, 99)]);
check('charges before the first / after the last reading are ignored', $r['intervals'][0]['kwh'], 0.0);
$r = EfficiencyCalc::intervals([$rd($T0 + 2 * $D, 300), $rd($T0, 100), $rd($T0 + $D, 200)], [$ch($T0 + $D + 5, 8), $ch($T0 + 5, 4)]);
check('unsorted input is sorted', [count($r['intervals']), $r['intervals'][0]['kwh'], $r['intervals'][1]['kwh']], [2, 4.0, 8.0]);

echo "bad odometer readings\n";
$r = EfficiencyCalc::intervals([$rd($T0, 1000), $rd($T0 + $D, 0), $rd($T0 + 2 * $D, 1060)], [$ch($T0 + $H, 5), $ch($T0 + $D + $H, 9)]);
check('one reading of 0 between good ones is rejected (not 1060 km in the next interval)', [count($r['intervals']), $r['intervals'][0]['km']], [1, 60.0]);
check('...its km and both charges are kept in the merged interval', [$r['intervals'][0]['kwh'], $r['dropped']], [14.0, 1]);
$r = EfficiencyCalc::intervals([$rd($T0, 1000), $rd($T0 + $D, 1040), $rd($T0 + 2 * $D, 99999), $rd($T0 + 3 * $D, 1080)], []);
check('a wild high spike is rejected too', [count($r['intervals']), $r['intervals'][1]['km'], $r['dropped']], [2, 40.0, 1]);
$r = EfficiencyCalc::intervals([$rd($T0, 1000), $rd($T0 + $D, 1100), $rd($T0 + 2 * $D, 50), $rd($T0 + 3 * $D, 120), $rd($T0 + 4 * $D, 200)], [$ch($T0 + 3 * $D - $H, 4), $ch($T0 + 4 * $D - $H, 9)]);
check('a real reset (several low readings in a row) is kept: the interval across it is dropped, the rest counts', [count($r['intervals']), $r['dropped'], $r['intervals'][1]['km'], $r['intervals'][2]['km']], [3, 1, 70.0, 80.0]);
check('first and last readings are never rejected (no neighbour to judge them by)',
    [count(EfficiencyCalc::cleanReadings([$rd(1, 500), $rd(2, 100), $rd(3, 110)])['readings'])], [3]);
check('clean data is untouched', EfficiencyCalc::cleanReadings([$rd(1, 100), $rd(2, 150), $rd(3, 150), $rd(4, 200)])['rejected'], 0);
$r = EfficiencyCalc::intervals([$rd($T0, 1000), $rd($T0 + $D, 950)], []);
check('a last reading below the previous one cannot be judged: the interval is dropped and counted, nothing invented', [count($r['intervals']), $r['dropped']], [0, 1]);

echo "metrics(): short intervals are no longer thrown away\n";
$readings = []; $charges = []; $odo = 5000.0;
for ($d = 0; $d < 30; $d++) { $readings[] = $rd($T0 + $d * $D, $odo); $odo += 10; $charges[] = $ch($T0 + $d * $D + 2 * $H, 1.8, 2.5); }   // 10 km/day, 1.8 kWh/day
$m = EfficiencyCalc::metrics(EfficiencyCalc::intervals($readings, $charges)['intervals']);
check('30 daily 10-km trips: 18.0 kWh/100 km (the old filter returned nothing at all)', $m['kwh_per_100km'], 18.0);
check('km, kWh and kr/km', [$m['km'], $m['kwh'], $m['kr_per_km']], [290.0, 52.2, 0.25], 0.011);
check('km/kWh is the inverse', $m['km_per_kwh'], round(290 / 52.2, 2));

$r = EfficiencyCalc::intervals([$rd($T0, 0), $rd($T0 + $D, 100), $rd($T0 + 2 * $D, 200)], [$ch($T0 + $D + $H, 36)]);
$m = EfficiencyCalc::metrics($r['intervals']);
check('an interval without a charge counts its km; the next one brings the energy', [$m['km'], $m['kwh'], $m['kwh_per_100km']], [200.0, 36.0, 18.0]);

$r = EfficiencyCalc::intervals([$rd($T0, 0), $rd($T0 + $D, 100)], [$ch($T0 + $H, 10, 20.0), $ch($T0 + 2 * $H, 10, null)]);
$m = EfficiencyCalc::metrics($r['intervals']);
check('unpriced charge: estimated at the price of the priced ones', [$m['cost'], $m['kr_per_km'], $m['kr_estimated']], [40.0, 0.4, true]);
$m = EfficiencyCalc::metrics(EfficiencyCalc::intervals([$rd($T0, 0), $rd($T0 + $D, 100)], [$ch($T0 + $H, 10, 20.0)])['intervals']);
check('all priced: not flagged', [$m['cost'], $m['kr_estimated']], [20.0, false]);
$m = EfficiencyCalc::metrics([]);
check('no intervals: nulls, not division by zero', [$m['kwh_per_100km'], $m['km_per_kwh'], $m['kr_per_km'], $m['confidence']], [null, null, null, 'lav']);

echo "confidence()\n";
foreach ([[50, 5, 'lav'], [700, 90, 'lav'], [90, 150, 'lav'], [800, 150, 'middel'], [1700, 300, 'høj'], [4000, 800, 'høj'], [0, 50, 'lav'], [500, 0, 'lav']] as [$km, $kwh, $lvl])
    check("$km km / $kwh kWh -> $lvl", EfficiencyCalc::confidence($km, $kwh)['level'], $lvl);
check('the reason is always given', EfficiencyCalc::confidence(800, 150)['why'] !== '', true);

echo "periods\n";
$iv = fn(int $to, float $km = 10, float $kwh = 2) => ['from' => $to - $D, 'to' => $to, 'km' => $km, 'kwh' => $kwh, 'cost' => 3.0, 'unpricedKwh' => 0.0, 'charges' => 1];
check('endingBetween: both ends inclusive', count(EfficiencyCalc::endingBetween([$iv(100), $iv(200), $iv(300)], 100, 300)), 3);
check('endingBetween: excludes outside', count(EfficiencyCalc::endingBetween([$iv(100), $iv(200), $iv(300)], 101, 299)), 1);
$now = $T0 + 40 * $D;
$list = [$iv($now - 31 * $D), $iv($now - 29 * $D), $iv($now - 1 * $D), $iv($now)];
check('rolling 30 days', count(EfficiencyCalc::rolling($list, $now, 30)), 3);
check('rolling 90 days', count(EfficiencyCalc::rolling($list, $now, 90)), 4);

$cet = new DateTimeZone('Europe/Copenhagen'); $utc = new DateTimeZone('UTC');
$edge = [$iv(strtotime('2026-10-31T23:30:00Z'))];
check('an interval ending 31 Oct 23:30 UTC is a November drive in Copenhagen', array_keys(EfficiencyCalc::byMonth($edge, $cet)), ['2026-11']);
check('...and an October drive in UTC', array_keys(EfficiencyCalc::byMonth($edge, $utc)), ['2026-10']);
$mo = EfficiencyCalc::byMonth([$iv(strtotime('2026-11-05T10:00:00Z')), $iv(strtotime('2026-09-05T10:00:00Z')), $iv(strtotime('2026-10-05T10:00:00Z')), $iv(strtotime('2026-10-20T10:00:00Z'))], $cet);
check('months are sorted oldest first', array_keys($mo), ['2026-09', '2026-10', '2026-11']);
check('two intervals in October are summed', [$mo['2026-10']['intervals'], $mo['2026-10']['km'], $mo['2026-10']['kwh']], [2, 20.0, 4.0]);

echo "cumulative()\n";
$c = EfficiencyCalc::cumulative([['from' => 0, 'to' => 10, 'km' => 5, 'kwh' => 0, 'cost' => 0, 'unpricedKwh' => 0, 'charges' => 0],
                                 ['from' => 10, 'to' => 20, 'km' => 95, 'kwh' => 20, 'cost' => 40, 'unpricedKwh' => 0, 'charges' => 1]]);
check('no point until there is both km and energy', count($c), 1);
check('cumulative values', [$c[0]['totalKm'], $c[0]['totalKwh'], $c[0]['totalKmPerKwh'], $c[0]['totalKrPerKm'], $c[0]['timestamp']], [100.0, 20.0, 5.0, 0.4, 20]);

echo "backward compatibility with the old per-interval method\n";
function legacy_cumulative(array $readings, array $charges): array {   // the old route's algorithm for one vehicle
    usort($readings, fn($a, $b) => $a['ts'] <=> $b['ts']); $out = []; $rk = $rw = $rp = 0.0;
    for ($i = 1; $i < count($readings); $i++) {
        $km = $readings[$i]['odometer'] - $readings[$i - 1]['odometer']; $kwh = 0.0; $pris = 0.0;
        foreach ($charges as $c) if ($c['ts'] > $readings[$i - 1]['ts'] && $c['ts'] <= $readings[$i]['ts']) { $kwh += $c['kwh']; $pris += $c['cost'] ?? 0; }
        if ($km < 0) continue;
        if ($kwh > 0 && $km > 0) {
            if ($km < 20) continue;
            $rk += $km; $rw += $kwh; $rp += $pris;
            $out[] = ['timestamp' => $readings[$i]['ts'], 'totalKm' => $rk, 'totalKwh' => $rw, 'totalKmPerKwh' => round($rk / $rw, 2), 'totalKrPerKm' => round($rp / $rk, 2)];
        }
    }
    return $out;
}
mt_srand(11); $same = 0; $bad = 0;
for ($case = 0; $case < 3000; $case++) {
    $readings = []; $charges = []; $odo = mt_rand(1000, 90000); $t = $T0;
    $len = mt_rand(2, 25);
    for ($i = 0; $i < $len; $i++) {
        $readings[] = $rd($t, (float) $odo);
        $km = mt_rand(20, 400); $odo += $km; $t += mt_rand(1, 5) * $D;                       // every interval: >= 20 km ...
        $charges[] = $ch($t - mt_rand(1, 12) * $H, round(mt_rand(30, 700) / 10, 1), round(mt_rand(30, 900) / 10, 2));   // ... and a charge inside it
    }
    $readings[] = $rd($t, (float) $odo);
    $new = EfficiencyCalc::cumulative(EfficiencyCalc::intervals($readings, $charges)['intervals']);
    $old = legacy_cumulative($readings, $charges);
    $eq = count($new) === count($old);
    if ($eq) foreach ($new as $k => $p) foreach (['timestamp', 'totalKmPerKwh', 'totalKrPerKm'] as $f) if (abs($p[$f] - $old[$k][$f]) > 1e-9) $eq = false;
    $eq ? $same++ : $bad++;
}
check("$same of 3000 random runs identical to the old cumulative series (all intervals >= 20 km with a charge)", $bad, 0);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
