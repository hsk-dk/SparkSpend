<?php
/**
 * Unit tests for includes/Projection.php (no database needed).
 * Run:  php dev/test_projection.php
 */
require __DIR__ . '/../includes/Projection.php';

$fails = 0; $n = 0;
function check(string $name, $actual, $expected, float $eps = 1e-9): void {
    global $fails, $n; $n++;
    $ok = (is_float($actual) || is_float($expected)) && is_numeric($actual) && is_numeric($expected)
        ? abs($actual - $expected) <= $eps
        : $actual === $expected;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++;
    echo "  FAIL $name\n       expected " . json_encode($expected) . "\n       got      " . json_encode($actual) . "\n";
}
$D = 31;
$p = fn($mtd, $prevMtd, $prevFull, $lyMtd, $lyFull, $days) => Projection::fullMonth($mtd, $prevMtd, $prevFull, $lyMtd, $lyFull, (float)$days, $D);

echo "reliability()\n";
check('same pace -> 1',                    Projection::reliability(10, 10), 1.0);
check('2x pace is still comparable -> 1',  Projection::reliability(20, 10), 1.0);
check('0.5x pace is still comparable -> 1', Projection::reliability(5, 10), 1.0);
check('4x pace -> 0',                      Projection::reliability(40, 10), 0.0);
check('3x pace -> 2 - log2(3)',            Projection::reliability(30, 10), 2 - log(3, 2));
check('nothing yet this month -> 0',       Projection::reliability(0, 10), 0.0);
check('nothing in either period -> 1',     Projection::reliability(0, 0), 1.0);
check('anchor had nothing, we have -> 0',  Projection::reliability(5, 0), 0.0);

echo "fullMonth()\n";
check('no anchors, day 10 -> run-rate', $p(100, 0, 0, 0, 0, 10)['value'], 100 / 10 * $D);
check('no anchors, day 1.5 -> capped extrapolation', $p(10, 0, 0, 0, 0, 1.5)['value'], 10 / 1.5 * 4.5);
check('no anchors -> basis run_rate', $p(100, 0, 0, 0, 0, 10)['basis'], 'run_rate');
check('elapsed <= 0 -> null', $p(1, 0, 0, 0, 0, 0)['value'], null);

// comparable last-year anchor, day 5: conf = (5-3)/11
$r = $p(55, 0, 0, 50, 300, 5); $conf = 2 / 11; $anchor = 300 * (55 / 50); $run = 55 / 5 * $D;
check('comparable last year: anchor/run-rate blend', $r['value'], $anchor * (1 - $conf) + $run * $conf);
check('comparable last year -> basis last_year', $r['basis'], 'last_year');

// structural change: 4x pace vs last year. Old code clamped to 2x and kept trusting it.
$r = $p(40, 0, 0, 10, 100, 5);
check('4x pace on day 5: run-rate takes over', $r['value'], 40 / 5 * $D);
check('4x pace on day 5 -> basis run_rate', $r['basis'], 'run_rate');
$r = $p(40, 0, 0, 10, 100, 2);
check('4x pace on day 2 (< MIN_DAYS): anchor stays the prior (clamped 2x)', $r['value'], 200.0);
check('4x pace on day 2 -> basis last_year', $r['basis'], 'last_year');

// last year not comparable, last month is
$r = $p(40, 38, 250, 10, 100, 5);
check('last year off, last month fine -> prev_month', $r['basis'], 'prev_month');
check('prev_month value = anchor/run-rate blend', $r['value'], 250 * (40 / 38) * (1 - 2 / 11) + (40 / 5 * $D) * (2 / 11));

check('anchor had nothing, we have -> run-rate on day 5', $p(5, 0, 0, 0, 100, 5)['basis'], 'run_rate');
$r = $p(0, 0, 0, 0, 100, 5);
check('nothing yet in either period -> anchor x 1.0', $r['value'], 100.0 * (1 - 2 / 11));
check('nothing yet in either period -> basis last_year', $r['basis'], 'last_year');

foreach ([14, 20, 31] as $d) {
    $r = $p(7 * $d, 6 * $d, 200, 6 * $d, 190, $d);
    check("day $d: run-rate regardless of anchor", $r['value'], 7 * $d / $d * $D);
}

echo "backward compatibility: identical to the old formula whenever the anchor is comparable\n";
function legacy(float $mtd, float $prevMtd, float $prevFull, float $lyMtd, float $lyFull, float $el, int $dim): float {
    $run = $mtd / $el * $dim; $conf = max(0.0, min(1.0, ($el - 3) / 11));
    if ($lyFull > 0)   { $f = $lyMtd   > 0 ? $mtd / $lyMtd   : 1.0; return $lyFull   * max(0.5, min(2.0, $f)) * (1 - $conf) + $run * $conf; }
    if ($prevFull > 0) { $f = $prevMtd > 0 ? $mtd / $prevMtd : 1.0; return $prevFull * max(0.5, min(2.0, $f)) * (1 - $conf) + $run * $conf; }
    return $el < 3 ? $mtd / $el * min($dim, $el * 3) : $run;
}
mt_srand(7); $cmp = 0; $bad = 0;
for ($i = 0; $i < 50000; $i++) {
    $el = mt_rand(5, 310) / 10; $mtd = mt_rand(1, 500);
    $pace = mt_rand(50, 200) / 100;                       // comparable: 0.5x..2x
    $hasLy = mt_rand(0, 1); $hasPrev = mt_rand(0, 1);
    $lyMtd = $hasLy ? $mtd / $pace : 0; $lyFull = $hasLy ? mt_rand(50, 900) : 0;
    $prevMtd = $hasPrev ? $mtd / (mt_rand(50, 200) / 100) : 0; $prevFull = $hasPrev ? mt_rand(50, 900) : 0;
    $new = Projection::fullMonth($mtd, $prevMtd, $prevFull, $lyMtd, $lyFull, $el, 31)['value'];
    $old = legacy($mtd, $prevMtd, $prevFull, $lyMtd, $lyFull, $el, 31);
    $cmp++; if (abs($new - $old) > 1e-6) { $bad++; if ($bad < 3) echo "    mismatch el=$el mtd=$mtd ly=$lyMtd/$lyFull prev=$prevMtd/$prevFull new=$new old=$old\n"; }
}
check("$cmp random comparable cases identical to legacy", $bad, 0);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
