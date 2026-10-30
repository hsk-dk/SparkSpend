<?php
/**
 * Month-end projection for the dashboard (pure functions, unit-tested:
 *   php dev/test_projection.php).
 *
 * Early in a month the run-rate (mtd / days elapsed * days in month) is noise, so
 * the projection starts from an ANCHOR (the same month last year, or else last
 * month) scaled by this month's pace, and hands over to the run-rate as the
 * month progresses (day 3 -> 14). Late in the month the actual data dominates
 * whatever the anchor. With no anchor at all, the first days' extrapolation is
 * capped so day 1 never reports mtd * daysInMonth.
 *
 * Why last year is preferred over last month: season matters, especially for
 * heating. A heat pump can use many times more in January than in October, so
 * anchoring early October on September (or January on December) is
 * systematically wrong across season changes. The same month last year, scaled
 * by how this year is tracking, carries both the season shape and the drift.
 *
 * What is different from a plain "anchor x clamp(pace)":
 *  - Pace is compared with the anchor's own month-to-date. If it is far outside
 *    0.5x..2x the anchor is not comparable (a second car, a new heat pump, a
 *    holiday...). Clamping would hide that and keep trusting the anchor; here the
 *    anchor loses weight and the run-rate takes over sooner (after day 3).
 *  - If last year is not comparable, last month is tried before giving up.
 *  - The result says which basis it used, so the UI can show it.
 */
final class Projection {

    public const MIN_DAYS       = 3;    // below this the run-rate is noise
    public const FULL_CONF_DAYS = 14;   // from here the run-rate is trusted fully

    /**
     * How comparable an anchor is, judged from pace = mtd / anchorMtd.
     * 1.0 within 0.5x..2x, falling linearly to 0 at 0.25x / 4x (and beyond).
     */
    public static function reliability(float $mtd, float $anchorMtd): float {
        if ($anchorMtd <= 0) {
            // Nothing happened in the anchor's period yet: pace is unknowable.
            return $mtd <= 0 ? 1.0 : 0.0;
        }
        if ($mtd <= 0) return 0.0;
        $pace = $mtd / $anchorMtd;
        $d = abs(log($pace, 2));          // 1 = factor 2 either way
        return $d <= 1 ? 1.0 : max(0.0, 2.0 - $d);
    }

    /**
     * @return array{value:?float, basis:string}  basis: last_year | prev_month | run_rate | none
     */
    public static function fullMonth(
        float $mtd, float $prevMtd, float $prevFull,
        float $lyMtd, float $lyFull,
        float $daysElapsed, int $daysInMonth
    ): array {
        if ($daysElapsed <= 0 || $daysInMonth <= 0) return ['value' => null, 'basis' => 'none'];

        $runRate = $mtd / $daysElapsed * $daysInMonth;
        $conf    = max(0.0, min(1.0, ($daysElapsed - self::MIN_DAYS) / max(1, self::FULL_CONF_DAYS - self::MIN_DAYS)));

        $candidates = [];
        if ($lyFull > 0)   $candidates['last_year']  = ['full' => $lyFull,   'mtd' => $lyMtd,   'rel' => self::reliability($mtd, $lyMtd)];
        if ($prevFull > 0) $candidates['prev_month'] = ['full' => $prevFull, 'mtd' => $prevMtd, 'rel' => self::reliability($mtd, $prevMtd)];

        if (empty($candidates)) {
            // No anchor at all.
            if ($daysElapsed < self::MIN_DAYS) {
                // Cap the extrapolation so day 1 doesn't report mtd * daysInMonth.
                return ['value' => $mtd / $daysElapsed * min($daysInMonth, $daysElapsed * 3), 'basis' => 'run_rate'];
            }
            return ['value' => $runRate, 'basis' => 'run_rate'];
        }

        // Seasonal anchor wins when it is comparable (or at least as comparable as last month).
        $name = 'last_year';
        if (!isset($candidates['last_year'])) {
            $name = 'prev_month';
        } elseif (isset($candidates['prev_month'])
                  && $candidates['last_year']['rel'] < 0.5
                  && $candidates['prev_month']['rel'] > $candidates['last_year']['rel']) {
            $name = 'prev_month';
        }
        $a = $candidates[$name];

        // Anchor scaled by pace; the clamp only bounds the early-month prior.
        $pace = ($a['mtd'] > 0) ? ($mtd / $a['mtd']) : 1.0;
        $anchorProjection = $a['full'] * max(0.5, min(2.0, $pace));

        // A non-comparable anchor hands over to the run-rate as soon as the
        // run-rate is usable (>= MIN_DAYS); before that it stays the best prior.
        $confEff = $conf;
        if ($daysElapsed >= self::MIN_DAYS) {
            $confEff = $conf + (1 - $conf) * (1 - $a['rel']);
        }

        $value = $anchorProjection * (1 - $confEff) + $runRate * $confEff;
        return ['value' => $value, 'basis' => $confEff >= 0.5 ? 'run_rate' : $name];
    }
}
