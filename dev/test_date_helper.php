<?php
/**
 * DateHelper::splitChargeByDays must cope with charges that lack a timestamp
 * (Monta cancelled/error sessions have no startedAt; running ones no stoppedAt).
 * Regression: charge-analytics&showZeroKwh=true returned HTTP 500 (TypeError on NULL startedAt).
 *
 * Run:  php dev/test_date_helper.php
 */
require __DIR__ . '/../includes/DateHelper.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}

$split = DateHelper::splitChargeByDays('2026-03-01T10:00:00Z', '2026-03-01T12:00:00Z', 10.0, 20.0);
check('a normal charge lands on its day', array_keys($split) === ['2026-03-01'] && abs($split['2026-03-01']['kwh'] - 10.0) < 1e-9);

$byStop = DateHelper::splitChargeByDays(null, '2026-03-02T12:00:00Z', 0.0, 0.0);
check('NULL startedAt falls back to stoppedAt', count($byStop) === 1);

$byStart = DateHelper::splitChargeByDays('2026-03-02T12:00:00Z', null, 5.0, 10.0);
check('NULL stoppedAt falls back to startedAt', count($byStart) === 1);

check('no timestamps at all gives an empty result (no crash)', DateHelper::splitChargeByDays(null, null, 1.0, 1.0) === []);
check('empty strings count as missing', DateHelper::splitChargeByDays('', '', 1.0, 1.0) === []);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
