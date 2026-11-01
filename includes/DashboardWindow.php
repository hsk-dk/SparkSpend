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
}
