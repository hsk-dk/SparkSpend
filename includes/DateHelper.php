<?php
/**
 * Date Utility Functions
 *
 * Provides date parsing and charge-session splitting logic.
 */

class DateHelper {

    /**
     * Parse date range string in format "YYYY-MM-DD til YYYY-MM-DD"
     *
     * @param string $dateRange Date range string
     * @return array Array with 'start' and 'end' keys containing dates
     * @throws Exception If format is invalid
     */
    public static function parseDateRange(string $dateRange): array {
        $dates = [];

        if (strpos($dateRange, ' til ') !== false) {
            $dates = explode(' til ', $dateRange);
        } elseif (strpos($dateRange, ' to ') !== false) {
            $dates = explode(' to ', $dateRange);
        } else {
            throw new Exception("Invalid date range format. Use 'YYYY-MM-DD til YYYY-MM-DD'");
        }

        if (count($dates) !== 2) {
            throw new Exception("Invalid date range format. Use 'YYYY-MM-DD til YYYY-MM-DD'");
        }

        $start = trim($dates[0]);
        $end = trim($dates[1]);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            throw new Exception("Invalid date format. Use YYYY-MM-DD");
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Proportionally split a charge session across calendar days.
     *
     * A session that runs from 22:00 to 02:00 spans two local calendar days, so
     * 50% of kWh and cost belongs to each day. Timestamps are UTC ISO 8601 strings
     * (e.g. "2026-04-13T22:00:00Z"). PHP uses the app timezone for local midnight
     * boundaries, ensuring charges are attributed to the correct local calendar day.
     *
     * @return array ['Y-m-d' => ['kwh' => float, 'cost' => float, 'count' => int]]
     */
    public static function splitChargeByDays(
        string $startedAt,
        string $stoppedAt,
        float  $totalKwh,
        float  $totalCost
    ): array {
        $startTs = strtotime($startedAt);
        $stopTs  = strtotime($stoppedAt);

        if ($startTs === false || $stopTs === false || $stopTs <= $startTs) {
            $day = date('Y-m-d', $startTs ?: time());
            return [$day => ['kwh' => $totalKwh, 'cost' => $totalCost, 'count' => 1]];
        }

        $totalSeconds = $stopTs - $startTs;
        $result       = [];
        $cursor       = $startTs;
        $isFirstDay   = true;

        while ($cursor < $stopTs) {
            $nextMidnight = mktime(0, 0, 0,
                (int) date('n', $cursor),
                (int) date('j', $cursor) + 1,
                (int) date('Y', $cursor)
            );
            $sliceEnd = min($nextMidnight, $stopTs);
            $ratio    = ($sliceEnd - $cursor) / $totalSeconds;
            $day      = date('Y-m-d', $cursor);

            $result[$day] = [
                'kwh'   => $totalKwh  * $ratio,
                'cost'  => $totalCost * $ratio,
                'count' => $isFirstDay ? 1 : 0,
            ];

            $isFirstDay = false;
            $cursor     = $nextMidnight;
        }

        return $result;
    }
}
