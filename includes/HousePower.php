<?php
/**
 * House power views (Hus > Forbrug): the house meter split into heat pump, EV and "rest",
 * per day (one month), per month (one year), compared across years, and year-to-date, with
 * the estimated cost per row (CostEstimates::house). No HTTP and no cache here (see
 * routes/get_house_power_data.php).
 *
 * Every view returns ['payload' => array, 'complete' => bool]; complete=false means the prices
 * behind the cost estimate were incomplete (rate-limited), so the payload must not be cached.
 */
final class HousePower
{

    /**
     * Per-day breakdown for one month (YYYY-MM).
     * @return array{payload: array, complete: bool}
     */
    public static function daily(PDO $powerlogDb, PDO $chargesDb, string $month): array
    {
        $dayStart = $month . '-01';
        $dayEnd   = date('Y-m-t', strtotime($dayStart));

        // House meter
        $husByDay = [];
        try {
            $husByDay = PowerlogRepository::lagDelta($powerlogDb, 'powerloghus', $dayStart, $dayEnd);
        } catch (\Throwable $e) {
            error_log('getHousePowerData house daily: ' . $e->getMessage());
        }

        // HP meter
        $hpByDay = PowerlogRepository::lagDelta($powerlogDb, 'powerlogjord', $dayStart, $dayEnd);

        // EV internal — proportional midnight-split
        $evByDay = [];
        $stmt = $chargesDb->prepare("
            SELECT startedAt, stoppedAt, COALESCE(consumedKwh, 0) AS kwh
            FROM charges
            WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
        ");
        $stmt->execute([$dayEnd, $dayStart]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $splits = DateHelper::splitChargeByDays(
                $row['startedAt'], $row['stoppedAt'], floatval($row['kwh']), 0.0
            );
            foreach ($splits as $day => $slice) {
                if ($day < $dayStart || $day > $dayEnd) continue;
                $evByDay[$day] = ($evByDay[$day] ?? 0.0) + $slice['kwh'];
            }
        }

        // EV external
        foreach (self::extEvByKey($chargesDb, $dayStart, $dayEnd, 'Y-m-d') as $day => $kwh) {
            $evByDay[$day] = ($evByDay[$day] ?? 0.0) + $kwh;
        }

        // Merge into ordered day list
        $allDays = array_unique(array_merge(
            array_keys($husByDay), array_keys($hpByDay), array_keys($evByDay)
        ));
        sort($allDays);

        $days = [];
        foreach ($allDays as $day) {
            $hus  = $husByDay[$day] ?? 0.0;
            $ev   = round($evByDay[$day] ?? 0.0, 2);
            $hp   = round($hpByDay[$day] ?? 0.0, 2);
            $rest = round(max(0, $hus - $ev - $hp), 2);
            $days[] = ['day' => $day, 'hus' => round($hus, 2), 'ev' => $ev, 'hp' => $hp, 'rest' => $rest];
        }

        $_end = min($dayEnd, date('Y-m-d'));
        return self::withCost(['mode' => 'daily', 'days' => $days],
            CostEstimates::house('daily', $days, $dayStart, $_end));
    }

    /**
     * Per-month breakdown for one year (YYYY).
     * @return array{payload: array, complete: bool}
     */
    public static function monthly(PDO $powerlogDb, PDO $chargesDb, string $year): array
    {
        $yearStart = $year . '-01-01';
        $yearEnd   = $year . '-12-31';

        // House meter
        $husByMonth = [];
        try {
            $husByMonth = PowerlogRepository::lagDeltaByMonth($powerlogDb, 'powerloghus', $yearStart, $yearEnd);
        } catch (\Throwable $e) {
            error_log('getHousePowerData house monthly: ' . $e->getMessage());
        }

        // HP meter
        $hpByMonth = PowerlogRepository::lagDeltaByMonth($powerlogDb, 'powerlogjord', $yearStart, $yearEnd);

        // EV internal monthly — proportional midnight-split
        $evByMonth = [];
        $stmt = $chargesDb->prepare("
            SELECT startedAt, stoppedAt, COALESCE(consumedKwh, 0) AS kwh
            FROM charges
            WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
        ");
        $stmt->execute([$yearEnd, $yearStart]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $splits = DateHelper::splitChargeByDays(
                $row['startedAt'], $row['stoppedAt'], floatval($row['kwh']), 0.0
            );
            foreach ($splits as $day => $slice) {
                if ($day < $yearStart || $day > $yearEnd) continue;
                $month = substr($day, 0, 7);
                $evByMonth[$month] = ($evByMonth[$month] ?? 0.0) + $slice['kwh'];
            }
        }

        // EV external monthly
        foreach (self::extEvByKey($chargesDb, $yearStart, $yearEnd, 'Y-m') as $month => $kwh) {
            $evByMonth[$month] = ($evByMonth[$month] ?? 0.0) + $kwh;
        }

        // Emit all 12 months (skip if no data at all)
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
            $hus  = $husByMonth[$key] ?? 0.0;
            $ev   = round($evByMonth[$key] ?? 0.0, 2);
            $hp   = round($hpByMonth[$key] ?? 0.0, 2);
            $rest = round(max(0, $hus - $ev - $hp), 2);
            if ($hus > 0 || $ev > 0 || $hp > 0) {
                $months[] = ['month' => $key, 'hus' => round($hus, 2), 'ev' => $ev, 'hp' => $hp, 'rest' => $rest];
            }
        }

        $_end = $year === date('Y') ? date('Y-m-d') : $yearEnd;
        return self::withCost(['mode' => 'monthly', 'months' => $months],
            CostEstimates::house('monthly', $months, $yearStart, $_end));
    }

    /**
     * House meter monthly totals across all years.
     * @return array{payload: array, complete: bool}
     */
    public static function compare(PDO $powerlogDb): array
    {
        $data = [];
        try {
            $stmt = $powerlogDb->prepare("
                WITH daily_max AS (
                    SELECT DATE(logdate) AS day, MAX(kwh) AS max_kwh
                    FROM powerloghus
                    GROUP BY DATE(logdate)
                ),
                daily_delta AS (
                    SELECT day,
                           strftime('%Y', day) AS year,
                           strftime('%m', day) AS month,
                           MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
                    FROM daily_max
                )
                SELECT year, month, ROUND(SUM(delta_kwh), 2) AS kwh
                FROM daily_delta
                GROUP BY year, month
                HAVING COUNT(DISTINCT day) >=
                    CAST(strftime('%d', date(
                        year || '-' || month || '-01', '+1 month', '-1 day'
                    )) AS INTEGER) * 0.9
                ORDER BY year DESC, month
            ");
            $stmt->execute([]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('getHousePowerData compare: ' . $e->getMessage());
        }

        $_years = array_values(array_filter(array_map(fn($r) => (int) $r['year'], $data)));
        $_start = ($_years ? min($_years) : (int) date('Y') - 2) . '-01-01';
        return self::withCost(['mode' => 'compare', 'data' => $data],
            $data ? CostEstimates::house('compare', $data, $_start, date('Y-m-d')) : null);
    }

    /**
     * House meter daily totals per year, January 1 through today's day-of-year.
     * @return array{payload: array, complete: bool}
     */
    public static function ytd(PDO $powerlogDb): array
    {
        $todayMd = date('m-d');
        $data    = [];
        try {
            $stmt = $powerlogDb->prepare("
                WITH daily_max AS (
                    SELECT DATE(logdate)           AS day,
                           strftime('%Y', logdate) AS year,
                           MAX(kwh)                AS max_kwh
                    FROM powerloghus
                    WHERE strftime('%m-%d', logdate) <= ?
                    GROUP BY DATE(logdate)
                ),
                daily_delta AS (
                    SELECT day, year,
                           COALESCE(MAX(max_kwh - LAG(max_kwh) OVER (PARTITION BY year ORDER BY day), 0), 0) AS kwh
                    FROM daily_max
                )
                SELECT year, day, ROUND(kwh, 2) AS kwh
                FROM daily_delta
                ORDER BY year, day
            ");
            $stmt->execute([$todayMd]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('getHousePowerData ytd: ' . $e->getMessage());
        }

        return ['payload' => ['mode' => 'ytd', 'data' => $data], 'complete' => true];
    }

    /** Last successful house-meter sync. */
    public static function syncStatus(PDO $powerlogDb): array
    {
        $stmt = $powerlogDb->prepare(
            "SELECT last_sync_timestamp, updated_at FROM sync_log WHERE source = 'housepowerlog' LIMIT 1"
        );
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['last_sync_timestamp' => null, 'updated_at' => null];
    }

    /** Attach the cost estimate; the payload is cacheable only when its prices were complete. */
    private static function withCost(array $resp, ?array $cost): array
    {
        $complete = true;
        if ($cost !== null) { $complete = $cost['complete']; unset($cost['complete']); }
        $resp['cost'] = $cost;
        return ['payload' => $resp, 'complete' => $complete];
    }

    // Helper: accumulate EV external charges by date key.
    private static function extEvByKey(PDO $chargesDb, string $from, string $to, string $keyFmt): array {
        $rows = ChargeRepository::getExternal($chargesDb, $from, $to);
        $result = [];
        foreach ($rows as $row) {
            $t = strtotime($row['datetime']);
            if (!$t) continue;
            $key = date($keyFmt, $t);
            $result[$key] = ($result[$key] ?? 0.0) + floatval($row['kwh']);
        }
        return $result;
    }

}
