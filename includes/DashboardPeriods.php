<?php
/**
 * The calendar of one dashboard request: today, this month, the same stretch last month and last year, and how
 * much of today has passed. Everything is derived from one instant ($now), so the figures of a request agree
 * with each other, and a test can build the dashboard for any moment (see dev/golden/).
 *
 * Was the first 60 lines of DashboardSummary::build(): thirty loose variables. The names are the same.
 */
final class DashboardPeriods
{
    /** @param string[] $last30Days the 30 days up to and including today, oldest first (sparklines) */
    private function __construct(
        public readonly int $now,
        public readonly DateTimeImmutable $todayDt,
        public readonly string $today,
        public readonly int $dayOfMonth,
        public readonly int $daysInMonth,
        public readonly string $sparkStart,            // first day of this month
        public readonly string $sparkWindowStart,      // 29 days back
        public readonly string $meterWindowStart,      // meter queries must cover the month AND the sparkline
        public readonly string $prevStart,
        public readonly string $prevEnd,               // same day-of-month last month (capped at its last day)
        public readonly string $prevMonthEnd,
        public readonly string $lastYearPeriodStart,
        public readonly string $lastYearPeriodEnd,
        public readonly string $lastYearMonthStart,
        public readonly string $lastYearMonthEnd,
        public readonly float $dayFraction,
        public readonly float $daysElapsed,            // incl. the partial current day
        public readonly float $prevLastFrac,
        public readonly float $lyLastFrac,
        public readonly float $prevScale,
        public readonly float $lyScale,
        public readonly array $last30Days,
    ) {}

    public static function at(int $now): self
    {
        $todayDt        = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0, 0);
        $currentStartDt = $todayDt->modify('first day of this month');
        $currentDayIdx  = (int) $todayDt->format('j') - 1;

        $prevStartDt    = $todayDt->modify('first day of last month');
        $prevEndDt      = $prevStartDt->modify("+{$currentDayIdx} days");
        $prevMonthEndDt = $prevStartDt->modify('last day of this month');
        if ($prevEndDt > $prevMonthEndDt) {
            $prevEndDt = $prevMonthEndDt;
        }

        $lastYearPeriodStartDt = $currentStartDt->modify('-1 year');
        $lastYearPeriodEndDt   = $lastYearPeriodStartDt->modify("+{$currentDayIdx} days");
        $lastYearMonthEndDt    = $lastYearPeriodStartDt->modify('last day of this month');
        if ($lastYearPeriodEndDt > $lastYearMonthEndDt) {
            $lastYearPeriodEndDt = $lastYearMonthEndDt;
        }

        $today      = $todayDt->format('Y-m-d');
        $sparkStart = $currentStartDt->format('Y-m-d');

        $daysThisMonth = (int) date('d', $now);
        $daysInMonth   = (int) date('t', $now);

        // Today is only partly over. Averages, projections and "vs. previous month" comparisons must not treat it
        // as a full day, otherwise everything looks too low (worst early in the day / month).
        $dayStartTs = $todayDt->getTimestamp();
        $dayLenSec  = $todayDt->modify('+1 day')->getTimestamp() - $dayStartTs;   // 23/24/25 h (DST-safe)
        $dayFraction = max(0.05, min(1.0, ($now - $dayStartTs) / max(1, $dayLenSec)));
        // Elapsed days incl. the partial current day (floored so a run-rate computed shortly after midnight on the
        // 1st can't explode).
        $daysElapsed = max(0.25, ($daysThisMonth - 1) + $dayFraction);
        // The comparison windows end on today's day-of-month, so their last day should only count pro rata -
        // unless the other month is shorter and was capped, in which case its last day is a complete day.
        $prevLastFrac = ($prevEndDt->format('j') === $todayDt->format('j'))           ? $dayFraction : 1.0;
        $lyLastFrac   = ($lastYearPeriodEndDt->format('j') === $todayDt->format('j')) ? $dayFraction : 1.0;
        // A comparison window cut short by a shorter month holds fewer days than the current period; scale it to
        // the same number of days so flat usage shows no trend (see DashboardWindow::comparableScale).
        $prevScale = DashboardWindow::comparableScale($prevEndDt->format('j') !== $todayDt->format('j'), (int) $prevEndDt->format('j'), $daysElapsed);
        $lyScale   = DashboardWindow::comparableScale($lastYearPeriodEndDt->format('j') !== $todayDt->format('j'), (int) $lastYearPeriodEndDt->format('j'), $daysElapsed);

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[] = date('Y-m-d', strtotime("-{$i} days", $now));
        }

        return new self(
            now: $now,
            todayDt: $todayDt,
            today: $today,
            dayOfMonth: (int) $todayDt->format('j'),
            daysInMonth: $daysInMonth,
            sparkStart: $sparkStart,
            sparkWindowStart: $todayDt->modify('-29 days')->format('Y-m-d'),
            meterWindowStart: DashboardWindow::lagStart($sparkStart, $today),
            prevStart: $prevStartDt->format('Y-m-d'),
            prevEnd: $prevEndDt->format('Y-m-d'),
            prevMonthEnd: $prevMonthEndDt->format('Y-m-d'),
            lastYearPeriodStart: $lastYearPeriodStartDt->format('Y-m-d'),
            lastYearPeriodEnd: $lastYearPeriodEndDt->format('Y-m-d'),
            lastYearMonthStart: $lastYearPeriodStartDt->format('Y-m-d'),
            lastYearMonthEnd: $lastYearMonthEndDt->format('Y-m-d'),
            dayFraction: $dayFraction,
            daysElapsed: $daysElapsed,
            prevLastFrac: $prevLastFrac,
            lyLastFrac: $lyLastFrac,
            prevScale: $prevScale,
            lyScale: $lyScale,
            last30Days: $days,
        );
    }

    /**
     * Project this month's total. Thin wrapper over Projection::fullMonth() (see includes/Projection.php) bound to
     * the elapsed-day figures of this request.
     * @return array{value:?float, basis:string}
     */
    public function projectMonth(float $mtd, float $prevMtd, float $prevFull, float $lyMtd = 0.0, float $lyFull = 0.0): array
    {
        return Projection::fullMonth($mtd, $prevMtd, $prevFull, $lyMtd, $lyFull, $this->daysElapsed, $this->daysInMonth);
    }

    /**
     * Sum a [Y-m-d => value] map, counting the window's final day only pro rata ($lastFraction). Used for the "same
     * period" comparison windows so a partly elapsed today is compared with a partly elapsed day last month / year.
     */
    public static function sumWithPartialLastDay(array $map, string $lastDay, float $lastFraction): float
    {
        $sum = 0.0;
        foreach ($map as $day => $value) {
            $sum += ($day === $lastDay) ? $value * $lastFraction : $value;
        }
        return $sum;
    }

    /** A [Y-m-d => value] map as the 30-day sparkline list (missing days are 0), rounded to 2 decimals. */
    public function sparkline(array $byDay): array
    {
        $out = [];
        foreach ($this->last30Days as $day) {
            $out[] = round($byDay[$day] ?? 0, 2);
        }
        return $out;
    }
}
