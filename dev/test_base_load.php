<?php
/**
 * Unit tests for includes/BaseLoad.php (no database needed).
 * Run:  php dev/test_base_load.php
 */
require __DIR__ . '/../includes/BaseLoad.php';

$fails = 0; $n = 0;
function check(string $name, $actual, $expected): void {
    global $fails, $n; $n++;
    if ($actual === $expected) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name\n       expected " . json_encode($expected) . "\n       got      " . json_encode($actual) . "\n";
}
/** one day of hourly house kWh: night hours $night, the rest $day */
function dayOf(string $date, float $night, float $day): array {
    $o = []; for ($h = 0; $h < 24; $h++) $o[sprintf('%s %02d', $date, $h)] = ($h >= 1 && $h <= 5) ? $night : $day;
    return $o;
}

echo "daily()\n";
check('the mean of the 3 lowest hours, in watts',
    BaseLoad::daily(dayOf('2026-10-01', 0.2, 0.6), [], []), ['2026-10-01' => 200]);
check('heat pump consumption is subtracted',
    BaseLoad::daily(dayOf('2026-10-01', 0.5, 0.9), array_fill_keys(array_keys(dayOf('2026-10-01', 0, 0)), 0.3), []), ['2026-10-01' => 200]);
// hours 02-04 are charging hours and left out: the lowest remaining hours are 01 and 05 (0.2) and one day hour (0.6)
check('hours with EV charging are left out',
    BaseLoad::daily(dayOf('2026-10-01', 0.2, 0.6), [], ['2026-10-01 02' => 7.0, '2026-10-01 03' => 7.0, '2026-10-01 04' => 7.0]), ['2026-10-01' => 333]);
check('...so a charge over the night does not make the base load zero',
    BaseLoad::daily(dayOf('2026-10-01', 0.0, 0.6), [], array_fill_keys(['2026-10-01 01', '2026-10-01 02', '2026-10-01 03', '2026-10-01 04', '2026-10-01 05'], 7.0)), ['2026-10-01' => 600]);
check('a day with fewer than 18 usable hours is skipped',
    BaseLoad::daily(array_slice(dayOf('2026-10-01', 0.2, 0.6), 0, 12, true), [], []), []);
$mismatch = dayOf('2026-10-01', 0.2, 0.6);
check('hours where the house meter is below the heat pump meter are ignored',
    BaseLoad::daily($mismatch, ['2026-10-01 02' => 5.0], []), ['2026-10-01' => 200]);
check('a 23-hour day (DST) still counts', count(BaseLoad::daily(array_slice(dayOf('2026-03-29', 0.2, 0.6), 0, 23, true), [], [])), 1);

echo "monthly()\n";
$m = BaseLoad::monthly(['2026-10-01' => 200, '2026-10-02' => 220, '2026-10-03' => 900, '2026-11-01' => 150, '2026-11-02' => 170]);
check('median is robust against one odd day', $m['2026-10']['median'], 220);
check('min, max and day count', [$m['2026-10']['min'], $m['2026-10']['max'], $m['2026-10']['days']], [200, 900, 3]);
check('an even number of days averages the middle two', $m['2026-11']['median'], 160);
check('months come in order', array_keys($m), ['2026-10', '2026-11']);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
