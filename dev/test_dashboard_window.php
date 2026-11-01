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

echo "comparableScale()\n";
$cs = fn(string $today, string $other) => (function () use ($today, $other) {
    // other = 'Y-m' of the comparison month; mirrors the route: window = first N days, capped at that month's length
    $t = new DateTimeImmutable($today); $n = (int) $t->format('j');
    $len = (int) (new DateTimeImmutable($other . '-01'))->format('t');
    $endDay = min($n, $len);
    return DashboardWindow::comparableScale($endDay !== $n, $endDay, $n - 1 + 0.5);   // 12:00 -> half a day elapsed
})();
check('not capped -> exactly 1.0 (28 of 31 days nothing changes)', DashboardWindow::comparableScale(false, 15, 14.5), 1.0);
check('31 Oct vs September (30 days): 30.5 / 30',   round($cs('2026-10-31', '2026-09'), 6), round(30.5 / 30, 6));
check('31 Mar vs February (28 days): 30.5 / 28',    round($cs('2026-03-31', '2026-02'), 6), round(30.5 / 28, 6));
check('30 Mar vs February: 29.5 / 28',              round($cs('2026-03-30', '2026-02'), 6), round(29.5 / 28, 6));
check('29 Mar vs February: 28.5 / 28',              round($cs('2026-03-29', '2026-02'), 6), round(28.5 / 28, 6));
check('28 Mar vs February: window not capped -> 1.0', $cs('2026-03-28', '2026-02'), 1.0);
check('31 Jan vs December (31 days): 1.0',          $cs('2026-01-31', '2025-12'), 1.0);
check('empty window is guarded', DashboardWindow::comparableScale(true, 0, 10.0), 1.0);
check('29 Feb 2028 vs 2027 (28 days): scaled',      round($cs('2028-02-29', '2027-02'), 6), round(28.5 / 28, 6));
check('scaling a capped window to the current length gives a flat trend', (function () {
    $perDay = 12.0; $window = 30; $elapsed = 30.5;                    // flat usage, 31 Oct 12:00 vs September
    $prev = $perDay * $window * DashboardWindow::comparableScale(true, $window, $elapsed);
    return round(($perDay * $elapsed - $prev) / $prev * 100, 6);
})(), 0.0);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
