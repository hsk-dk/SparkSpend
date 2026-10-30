<?php
/**
 * Unit tests for includes/HourlyConsumption.php (no database needed).
 * Run:  php dev/test_hourly_consumption.php
 */
require __DIR__ . '/../includes/HourlyConsumption.php';

$fails = 0; $n = 0;
function check(string $name, $actual, $expected, float $eps = 1e-9): void {
    global $fails, $n; $n++;
    $ok = ($actual === $expected);
    if (!$ok && is_array($actual) && is_array($expected)) {
        $ok = array_keys($actual) === array_keys($expected);
        if ($ok) foreach ($actual as $k => $v) if (abs($v - $expected[$k]) > $eps) { $ok = false; break; }
    } elseif (!$ok && is_numeric($actual) && is_numeric($expected)) {
        $ok = abs($actual - $expected) <= $eps;
    }
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++;
    echo "  FAIL $name\n       expected " . json_encode($expected) . "\n       got      " . json_encode($actual) . "\n";
}
$rd = fn(string $t, float $k) => ['logdate' => $t, 'kwh' => $k];

echo "fromReadings()\n";
check('one reading per hour -> delta in the hour of the later reading',
    HourlyConsumption::fromReadings([$rd('2026-10-01 00:00:00', 100), $rd('2026-10-01 01:00:00', 101), $rd('2026-10-01 02:00:00', 103)]),
    ['2026-10-01 01' => 1.0, '2026-10-01 02' => 2.0]);
check('crosses midnight',
    HourlyConsumption::fromReadings([$rd('2026-10-01 23:00:00', 10), $rd('2026-10-02 00:00:00', 10.5)]),
    ['2026-10-02 00' => 0.5]);
check('counter reset interval counts as 0, the next interval is valid',
    HourlyConsumption::fromReadings([$rd('2026-10-01 00:00:00', 500), $rd('2026-10-01 01:00:00', 501), $rd('2026-10-01 02:00:00', 3), $rd('2026-10-01 03:00:00', 4)]),
    ['2026-10-01 01' => 1.0, '2026-10-01 03' => 1.0]);
check('sensor stall: the gap is spread evenly over the hours it covers',
    HourlyConsumption::fromReadings([$rd('2026-10-01 00:00:00', 10), $rd('2026-10-01 04:00:00', 18)]),
    ['2026-10-01 04' => 2.0, '2026-10-01 03' => 2.0, '2026-10-01 02' => 2.0, '2026-10-01 01' => 2.0]);
check('gap longer than 14 days is not guessed',
    HourlyConsumption::fromReadings([$rd('2026-09-01 00:00:00', 10), $rd('2026-10-01 00:00:00', 500)]), []);
check('no consumption -> no entries', HourlyConsumption::fromReadings([$rd('2026-10-01 00:00:00', 5), $rd('2026-10-01 01:00:00', 5)]), []);
check('fewer than 2 readings -> empty', HourlyConsumption::fromReadings([$rd('2026-10-01 00:00:00', 5)]), []);
$rows = []; $kwh = 1000.0;
for ($h = 0; $h < 200; $h++) { $kwh += 0.3 + ($h % 7) * 0.1; $rows[] = $rd((new DateTimeImmutable('2026-10-01 00:00:00'))->modify("+$h hours")->format('Y-m-d H:i:s'), $kwh); }
check('energy is conserved: sum of hours = last - first reading',
    array_sum(HourlyConsumption::fromReadings($rows)), $kwh - $rows[0]['kwh'], 1e-6);

echo "spreadCharge()\n";
$tz = 'Europe/Copenhagen';
check('winter charge 20:30-22:30 local is split by time in each hour',
    HourlyConsumption::spreadCharge('2026-01-15T19:30:00Z', '2026-01-15T21:30:00Z', 10.0, $tz),
    ['2026-01-15 20' => 2.5, '2026-01-15 21' => 5.0, '2026-01-15 22' => 2.5]);
check('summer uses UTC+2',
    HourlyConsumption::spreadCharge('2026-07-15T18:00:00Z', '2026-07-15T19:00:00Z', 6.0, $tz),
    ['2026-07-15 20' => 6.0]);
check('end not after start -> everything in the start hour',
    HourlyConsumption::spreadCharge('2026-01-15T19:30:00Z', '2026-01-15T19:30:00Z', 4.0, $tz), ['2026-01-15 20' => 4.0]);
check('null end -> everything in the start hour',
    HourlyConsumption::spreadCharge('2026-01-15T19:30:00Z', null, 4.0, $tz), ['2026-01-15 20' => 4.0]);
$dst = HourlyConsumption::spreadCharge('2026-10-24T22:30:00Z', '2026-10-25T02:30:00Z', 12.0, $tz);   // across the autumn DST change
check('across the DST change the kWh is conserved', array_sum($dst), 12.0, 1e-9);

echo "rest()\n";
check('house - heat pump - EV, per hour',
    HourlyConsumption::rest(['a' => 2.0, 'b' => 3.0], ['a' => 0.5, 'b' => 0.5], ['b' => 1.0]), ['a' => 1.5, 'b' => 1.5]);
check('never negative', HourlyConsumption::rest(['a' => 1.0], ['a' => 0.5], ['a' => 2.0]), ['a' => 0.0]);
check('hours without hp/ev data keep the house value', HourlyConsumption::rest(['a' => 1.0], [], []), ['a' => 1.0]);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
