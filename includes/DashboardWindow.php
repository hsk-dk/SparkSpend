<?php
/**
 * Date window for the dashboard's meter queries (pure, unit-tested:
 *   php dev/test_dashboard_window.php).
 *
 * The heat pump / house series are fetched ONCE for a window that has to cover
 * both things the dashboard needs from it:
 *   - the month to date                  (starts on the 1st)
 *   - the rolling 30-day sparkline       (starts today - 29 days)
 * The window must therefore start at the EARLIER of the two. Using only the
 * sparkline start drops the 1st of the month on the 31st of a 31-day month
 * (today - 29 days = the 2nd), understating the month-to-date by a day.
 */
final class DashboardWindow {

    /**
     * @param string $monthStart  'Y-m-d' of the 1st of the current month
     * @param string $today       'Y-m-d'
     * @param int    $sparkDays   length of the rolling sparkline, in days
     */
    public static function lagStart(string $monthStart, string $today, int $sparkDays = 30): string {
        $sparkStart = (new DateTimeImmutable($today))->modify('-' . ($sparkDays - 1) . ' days')->format('Y-m-d');
        return min($monthStart, $sparkStart);     // ISO dates sort correctly as strings
    }

    /**
     * Factor that makes a CAPPED comparison window comparable to the current period.
     *
     * "Same period last month" is the first N days of last month, N = today's
     * day-of-month. When last month is shorter than N (the 31st vs a 30-day month,
     * the 29th-31st vs February) the window is cut at that month's last day and holds
     * fewer days than the current period. Compared as totals, FLAT usage looks like a
     * rising trend: +3 % for 31 vs 30 days, up to +11 % for 31 vs February's 28.
     * Scaling the window to the current number of days removes that.
     *
     * @param bool  $capped       the window ended before today's day-of-month
     * @param int   $windowDays   whole days the capped window holds (= its last day-of-month)
     * @param float $daysElapsed  days elapsed in the current period (incl. the partial day)
     * @return float 1.0 when the window is not capped (nothing changes on 28 of 31 days a month)
     */
    public static function comparableScale(bool $capped, int $windowDays, float $daysElapsed): float {
        return ($capped && $windowDays > 0) ? $daysElapsed / $windowDays : 1.0;
    }
}
