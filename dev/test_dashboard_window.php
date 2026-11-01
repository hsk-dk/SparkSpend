<?php
/** Run: php dev/test_dashboard_window.php */
require __DIR__ . '/../includes/DashboardWindow.php';
$fails = 0; $n = 0;
function check(string $name, $actual, $expected): void {
    global $fails, $n; $n++;
    if ($actual === $expected) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name\n       expected " . json_encode($expected) . "\n       got      " . json_encode($actual) . "\n";
}
$w = fn(string $today) => DashboardWindow::lagStart(substr($today, 0, 8) . '01', $today);

check('31st of a 31-day month: window must include the 1st (the regression)', $w('2026-10-31'), '2026-10-01');
check('same, December',                                                        $w('2026-12-31'), '2026-12-01');
check('30th of a 31-day month: the 1st is also today-29',                      $w('2026-10-30'), '2026-10-01');
check('30th of a 30-day month: window starts on the 1st',                      $w('2026-11-30'), '2026-11-01');
check('15th: the rolling window reaches back into the previous month',         $w('2026-10-15'), '2026-09-16');
check('1st: the rolling window reaches back into the previous month',          $w('2026-10-01'), '2026-09-02');
check('Feb 28: rolling window reaches back into January',                      $w('2026-02-28'), '2026-01-30');
check('window never starts later than the month start (every day of 2026)', (function () {
    for ($d = new DateTimeImmutable('2026-01-01'); $d <= new DateTimeImmutable('2026-12-31'); $d = $d->modify('+1 day')) {
        $t = $d->format('Y-m-d'); $ms = $d->format('Y-m-01');
        if (DashboardWindow::lagStart($ms, $t) > $ms) return $t;
    }
    return 'ok';
})(), 'ok');
check('window always covers the whole 30-day sparkline (every day of 2026)', (function () {
    for ($d = new DateTimeImmutable('2026-01-01'); $d <= new DateTimeImmutable('2026-12-31'); $d = $d->modify('+1 day')) {
        $t = $d->format('Y-m-d');
        if (DashboardWindow::lagStart($d->format('Y-m-01'), $t) > $d->modify('-29 days')->format('Y-m-d')) return $t;
    }
    return 'ok';
})(), 'ok');
echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
