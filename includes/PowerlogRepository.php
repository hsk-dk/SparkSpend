<?php
/**
 * Powerlog Repository
 *
 * Provides LAG-based daily delta calculations for cumulative energy meters
 * (powerlogjord, powerloghus). Includes automatic sensor-stall and data-gap
 * interpolation.
 */

class PowerlogRepository {

    /**
     * Compute daily kWh deltas from a cumulative-meter table using a LAG window function.
     *
     * Deltas are computed between consecutive (≈hourly) readings and summed per
     * day, so a meter reset only loses the single reset interval rather than the
     * whole day's post-reset consumption. Abnormally large deltas (sensor stall
     * recovery) are redistributed across the preceding gap period.
     *
     * @param PDO    $db    SQLite connection (powerlog database)
     * @param string $table Table name — must be 'powerlogjord' or 'powerloghus'
     * @param string $from  Start date YYYY-MM-DD (inclusive)
     * @param string $to    End date   YYYY-MM-DD (inclusive)
     * @return array  Associative array keyed by 'YYYY-MM-DD' => float kWh delta
     * @throws InvalidArgumentException if table name is not whitelisted
     */
    public static function lagDelta(PDO $db, string $table, string $from, string $to): array {
        static $allowed = ['powerlogjord', 'powerloghus'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException("lagDelta: table '$table' is not whitelisted");
        }

        // Max plausible daily consumption (kWh) per meter type.
        $maxDailyKwh = ($table === 'powerloghus') ? 150.0 : 80.0;

        // Reading-level delta (readings are ~hourly). We diff each reading against
        // the previous one and sum the positive increments per day. This is more
        // accurate around meter resets than diffing daily MAX values: on a reset
        // day, every post-reset reading still produces a valid positive delta, so
        // the post-reset consumption is captured at hourly resolution instead of
        // being dropped. A reset shows up as a single negative interval
        // (curr < prev) whose consumption is unknown from the counter alone, so
        // that one interval is skipped (treated as 0). Verified to produce
        // identical results to the previous daily-MAX approach for normal
        // (non-reset) data across the full history.
        $sql = "
            WITH ordered AS (
                SELECT logdate,
                       kwh,
                       LAG(kwh) OVER (ORDER BY logdate) AS prev_kwh
                FROM $table
                WHERE DATE(logdate) >= DATE(?, '-1 day')
                  AND DATE(logdate) <= ?
            ),
            deltas AS (
                SELECT DATE(logdate) AS day,
                       CASE
                           WHEN prev_kwh IS NULL     THEN 0
                           WHEN kwh - prev_kwh < 0   THEN 0   -- reset interval: unknown
                           ELSE kwh - prev_kwh
                       END AS d
                FROM ordered
            )
            SELECT day, ROUND(SUM(d), 2) AS kwh
            FROM deltas
            WHERE day BETWEEN ? AND ?
            GROUP BY day
            ORDER BY day
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$from, $to, $from, $to]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['day']] = floatval($row['kwh']);
        }

        // Post-process: redistribute abnormally large deltas.
        $days = array_keys($result);
        for ($i = 0, $n = count($days); $i < $n; $i++) {
            $day = $days[$i];
            if ($result[$day] <= $maxDailyKwh) continue;

            $stallDays = 0;

            // Pattern 1: preceding zero-delta days (sensor stall with data present)
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($result[$days[$j]] > 0.0) break;
                $stallDays++;
            }

            // Pattern 2: calendar-day gap (no data between previous day and spike day)
            if ($i > 0 && $stallDays === 0) {
                $prevDay  = new \DateTimeImmutable($days[$i - 1]);
                $thisDay  = new \DateTimeImmutable($day);
                $gapDays  = (int)$prevDay->diff($thisDay)->days - 1;
                if ($gapDays > 0) {
                    $stallDays = $gapDays;
                }
            }

            if ($stallDays === 0) continue;

            $totalToSpread = $result[$day];
            $spreadDays    = $stallDays + 1;
            $perDay        = round($totalToSpread / $spreadDays, 2);

            // Pattern 1: assign to existing entries
            for ($j = $i - $stallDays; $j < $i; $j++) {
                if ($j >= 0 && isset($days[$j])) {
                    $result[$days[$j]] = $perDay;
                }
            }

            // Pattern 2: insert synthetic entries for gap days
            if ($i > 0) {
                $prevDay = new \DateTimeImmutable($days[$i - 1]);
                $thisDay = new \DateTimeImmutable($day);
                $gapCalendarDays = (int)$prevDay->diff($thisDay)->days - 1;
                if ($gapCalendarDays > 0 && $stallDays === $gapCalendarDays) {
                    for ($g = 1; $g <= $gapCalendarDays; $g++) {
                        $syntheticDay = $prevDay->modify("+{$g} days")->format('Y-m-d');
                        $result[$syntheticDay] = $perDay;
                    }
                }
            }

            $result[$day] = $perDay;
            $assigned = $perDay * $spreadDays;
            $remainder = round($totalToSpread - $assigned, 2);
            if ($remainder != 0) {
                $result[$day] += $remainder;
            }
        }

        ksort($result);
        return $result;
    }

    /**
     * Sum lagDelta results by month (YYYY-MM).
     */
    public static function lagDeltaByMonth(PDO $db, string $table, string $from, string $to): array {
        $daily = self::lagDelta($db, $table, $from, $to);
        $byMonth = [];
        foreach ($daily as $day => $kwh) {
            $month = substr($day, 0, 7);
            $byMonth[$month] = round(($byMonth[$month] ?? 0.0) + $kwh, 4);
        }
        foreach ($byMonth as $m => $v) {
            $byMonth[$m] = round($v, 2);
        }
        return $byMonth;
    }

    /**
     * Sum lagDelta results by year (YYYY).
     */
    public static function lagDeltaByYear(PDO $db, string $table, string $from, string $to): array {
        $daily = self::lagDelta($db, $table, $from, $to);
        $byYear = [];
        foreach ($daily as $day => $kwh) {
            $year = substr($day, 0, 4);
            $byYear[$year] = round(($byYear[$year] ?? 0.0) + $kwh, 4);
        }
        foreach ($byYear as $y => $v) {
            $byYear[$y] = round($v, 2);
        }
        return $byYear;
    }
}
