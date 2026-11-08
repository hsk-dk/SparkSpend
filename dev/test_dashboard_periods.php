<?php
/**
 * DashboardPeriods: the calendar of a dashboard request, at awkward instants (month change, a short February,
 * the 23- and 25-hour DST days, a leap day). Run:  php dev/test_dashboard_periods.php
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
$at = fn(string $local) => DashboardPeriods::at(strtotime($local));
$near = fn(float $a, float $b) => abs($a - $b) < 1e-9;

echo "an ordinary day\n";
$p = $at('2026-11-08 12:00:00');
check('today, this month and the sparkline window', $p->today === '2026-11-08' && $p->sparkStart === '2026-11-01' && $p->sparkWindowStart === '2026-10-10');
check('the same stretch last month and last year', $p->prevStart === '2026-10-01' && $p->prevEnd === '2026-10-08' && $p->prevMonthEnd === '2026-10-31'
    && $p->lastYearPeriodStart === '2025-11-01' && $p->lastYearPeriodEnd === '2025-11-08' && $p->lastYearMonthEnd === '2025-11-30');
check('half of today has passed at noon, 7.5 days elapsed', $near($p->dayFraction, 0.5) && $near($p->daysElapsed, 7.5));
check('the comparison windows end on today\'s day-of-month, so their last day counts pro rata', $near($p->prevLastFrac, 0.5) && $near($p->lyLastFrac, 0.5));
check('nothing is capped, so nothing is scaled', $near($p->prevScale, 1.0) && $near($p->lyScale, 1.0));
check('30 days of sparkline, oldest first, ending today', count($p->last30Days) === 30 && $p->last30Days[0] === '2026-10-10' && $p->last30Days[29] === '2026-11-08');
check('day of month and days in month', $p->dayOfMonth === 8 && $p->daysInMonth === 30);

echo "edges\n";
$p = $at('2026-11-01 00:20:00');
check('just after midnight on the 1st the day fraction is floored (0.05), 0.25 days elapsed - no exploding run rate', $near($p->dayFraction, 0.05) && $near($p->daysElapsed, 0.25), (string) $p->daysElapsed);
$p = $at('2026-03-31 23:50:00');
check('the 31st after a 28-day February: last month\'s window is capped at the 28th', $p->prevEnd === '2026-02-28' && $p->prevMonthEnd === '2026-02-28');
check('...its last day is a complete day (not pro rata), and the window is scaled up to the same number of days', $near($p->prevLastFrac, 1.0) && $p->prevScale > 1.0, (string) $p->prevScale);
check('...last year\'s March has 31 days, so that window is not capped', $p->lastYearPeriodEnd === '2025-03-31' && $near($p->lyScale, 1.0));
$p = $at('2028-02-29 18:00:00');
check('29 February: last year\'s window is capped at 28 February 2027', $p->lastYearPeriodEnd === '2027-02-28' && $p->lyScale > 1.0);
$p = $at('2027-01-01 01:00:00');
check('new year: last month is December of the year before, last year is the same January', $p->prevStart === '2026-12-01' && $p->lastYearPeriodStart === '2026-01-01' && $p->daysInMonth === 31);

echo "daylight saving time\n";
$p = $at('2026-03-29 12:00:00');   // 23-hour day (clocks forward at 02:00)
check('the spring day is 23 hours: at noon 11 of 23 hours have passed', $near($p->dayFraction, 11 / 23), (string) $p->dayFraction);
$p = $at('2026-10-25 12:00:00');   // 25-hour day
check('the autumn day is 25 hours: at noon 13 of 25 hours have passed', $near($p->dayFraction, 13 / 25), (string) $p->dayFraction);

echo "helpers\n";
check('sumWithPartialLastDay counts only the last day pro rata', $near(DashboardPeriods::sumWithPartialLastDay(['2026-10-07' => 10.0, '2026-10-08' => 10.0], '2026-10-08', 0.5), 15.0));
check('...and a missing last day adds nothing', $near(DashboardPeriods::sumWithPartialLastDay(['2026-10-07' => 10.0], '2026-10-08', 0.5), 10.0));
$p = $at('2026-11-08 12:00:00');
$s = $p->sparkline(['2026-11-08' => 1.234, '2026-11-07' => 2]);
check('sparkline: 30 values, missing days are 0, rounded to 2 decimals', count($s) === 30 && $s[29] === 1.23 && $s[28] === 2.0 && $s[0] === 0.0 && $s[10] === 0.0);
$proj = $p->projectMonth(100.0, 90.0, 300.0);
check('projectMonth is Projection::fullMonth bound to this request\'s elapsed days', $proj === Projection::fullMonth(100.0, 90.0, 300.0, 0.0, 0.0, $p->daysElapsed, $p->daysInMonth));
check('a different instant gives a different calendar (nothing is read from the real clock)', $at('2020-02-15 08:00:00')->today === '2020-02-15' && $at('2020-02-15 08:00:00')->daysInMonth === 29);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
