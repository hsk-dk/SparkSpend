<?php
/**
 * Unit tests for includes/VehiclePairing.php (no database needed).
 * Run:  php dev/test_vehicle_pairing.php
 */
require __DIR__ . '/../includes/VehiclePairing.php';

$fails = 0; $n = 0;
function check(string $name, $actual, $expected): void {
    global $fails, $n; $n++;
    if ($actual === $expected) { echo "  ok   $name\n"; return; }
    $fails++;
    echo "  FAIL $name\n       expected " . json_encode($expected) . "\n       got      " . json_encode($actual) . "\n";
}

const ENYAQ = 2; const EUP = 3;
$H = 3600; $GRACE = 300; $LOOK = 172800; $AMB = 600;
$T = 1_800_000_000;                       // charge's cablePluggedInAt
$m = fn(array $w, int $t = 0) => VehiclePairing::match($w, $T + $t, $GRACE, $LOOK, $AMB);

echo "match()\n";
$r = $m([]);
check('no readings -> unknown, no vehicle (no default car)', [$r['vehicleId'], $r['source']], [null, 'unknown']);

$r = $m([['vehicleId' => ENYAQ, 'ts' => $T - 1 * $H]]);
check('single recent reading -> matched', [$r['vehicleId'], $r['source']], [ENYAQ, 'matched']);

$r = $m([['vehicleId' => ENYAQ, 'ts' => $T - 30 * $H], ['vehicleId' => EUP, 'ts' => $T - 2 * $H]]);
check('latest reading wins (e-Up after Enyaq)', [$r['vehicleId'], $r['source']], [EUP, 'matched']);

$r = $m([['vehicleId' => EUP, 'ts' => $T - 30 * $H], ['vehicleId' => ENYAQ, 'ts' => $T - 2 * $H]]);
check('latest reading wins (Enyaq after e-Up)', [$r['vehicleId'], $r['source']], [ENYAQ, 'matched']);

$r = $m([['vehicleId' => ENYAQ, 'ts' => $T - 3 * 86400]]);
check('stale reading (>48h) -> uncertain', [$r['vehicleId'], $r['source']], [ENYAQ, 'uncertain']);

$r = $m([['vehicleId' => EUP, 'ts' => $T - 20 * 60], ['vehicleId' => ENYAQ, 'ts' => $T - 15 * 60]]);
check('other car plugged in 5 min earlier -> uncertain', [$r['vehicleId'], $r['source']], [ENYAQ, 'uncertain']);

$r = $m([['vehicleId' => EUP, 'ts' => $T - 2 * $H], ['vehicleId' => ENYAQ, 'ts' => $T - 15 * 60]]);
check('other car plugged in long before -> still matched', [$r['vehicleId'], $r['source']], [ENYAQ, 'matched']);

$r = $m([['vehicleId' => ENYAQ, 'ts' => $T - $H], ['vehicleId' => EUP, 'ts' => $T + 120]]);
check('reading within grace AFTER T is eligible', [$r['vehicleId']], [EUP]);

$r = $m([['vehicleId' => ENYAQ, 'ts' => $T - $H], ['vehicleId' => EUP, 'ts' => $T + 900]]);
check('reading beyond grace after T is ignored', [$r['vehicleId'], $r['source']], [ENYAQ, 'matched']);

$r = $m([['vehicleId' => ENYAQ, 'ts' => $T + 4000]]);
check('only a later reading -> unknown', [$r['vehicleId'], $r['source']], [null, 'unknown']);

echo "rank()\n";
check('order manual>api>matched>uncertain>default=legacy>unknown',
    array_map([VehiclePairing::class, 'rank'], ['manual', 'api', 'matched', 'uncertain', 'default', 'legacy', 'unknown', null]),
    [5, 4, 3, 2, 1, 1, 0, 0]);

echo "resolve() — re-sync of an existing charge\n";
$res = fn($ev, $es, $nv, $ns) => array_values(VehiclePairing::resolve($ev, $es, $nv, $ns));
check('manual is never overwritten by matched',      $res(3, 'manual', 2, 'matched'),   [3, 'manual']);
check('manual is never overwritten by api',          $res(3, 'manual', 2, 'api'),       [3, 'manual']);
check('matched is not downgraded to uncertain',      $res(3, 'matched', 2, 'uncertain'), [3, 'matched']);
check('matched is not wiped by unknown',             $res(3, 'matched', null, 'unknown'), [3, 'matched']);
check('matched does not flip car on equal rank',     $res(3, 'matched', 2, 'matched'),  [3, 'matched']);
check('uncertain is upgraded by matched',            $res(3, 'uncertain', 2, 'matched'), [2, 'matched']);
check('uncertain stays on equal rank',               $res(3, 'uncertain', 2, 'uncertain'), [3, 'uncertain']);
check('legacy is upgraded by matched',               $res(1, 'legacy', 2, 'matched'),   [2, 'matched']);
check('legacy is not wiped by unknown',              $res(1, 'legacy', null, 'unknown'), [1, 'legacy']);
check('api beats matched',                           $res(3, 'matched', 2, 'api'),      [2, 'api']);
check('no vehicle yet -> take new match',            $res(null, 'unknown', 2, 'matched'), [2, 'matched']);
check('vehicleId 0 counts as "no vehicle"',          $res(0, 'default', 3, 'uncertain'), [3, 'uncertain']);
check('new charge with no evidence stays unknown',   $res(null, null, null, 'unknown'),  [null, 'unknown']);
check('NULL source on existing row treated as legacy', $res(2, null, 3, 'matched'),     [3, 'matched']);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
