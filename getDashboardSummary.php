<?php
/**
 * Dashboard Summary API
 * Returns lightweight summary data for both EV and heatpump systems
 * for the current month + 14-day sparklines.
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

// ─── 60-second file cache ─────────────────────────────────────────────────────
// Key includes today's date so it resets at midnight automatically.
$_dashCacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'sparkspend_dashboard_' . date('Y-m-d') . '.json';
if (file_exists($_dashCacheFile) && (time() - filemtime($_dashCacheFile)) < 60) {
    echo file_get_contents($_dashCacheFile);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

try {
    $chargesDb = DatabaseManager::getChargesDb();
    $powerlogDb = DatabaseManager::getPowerlogDb();

    $currentMonth  = date('Y-m');
    $today         = date('Y-m-d');
    $sparkStart    = date('Y-m-01');        // first of current month
    $prevStart     = date('Y-m-01', strtotime('first day of last month'));
    $prevEnd       = date('Y-m-d', strtotime('-1 month')); // same day last month
    $lastYearStart = date('Y-m-01', strtotime('-1 year')); // first of same month last year
    $lastYearEnd   = date('Y-m-d', strtotime('-1 year'));  // same day last year

    // =========================================================================
    // EV — internal charges: raw fetch, proportional midnight-split
    // =========================================================================
    $stmtInt = $chargesDb->prepare("
        SELECT startedAt, stoppedAt,
               COALESCE(consumedKwh, 0) AS kwh,
               COALESCE(cost, 0) AS cost
        FROM charges
        WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
    ");
    $stmtInt->execute([$today, $lastYearStart]);

    $intMonthCnt  = 0;  $intMonthKwh  = 0.0;  $intMonthCost = 0.0;
    $intPrevKwh   = 0.0; $intPrevCost  = 0.0;
    $intLYKwh     = 0.0; $intLYCost    = 0.0;
    $evByDay      = [];

    foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $splits = QueryBuilder::splitChargeByDays(
            $row['startedAt'], $row['stoppedAt'],
            floatval($row['kwh']), floatval($row['cost'])
        );
        $countedForMonth = false;
        foreach ($splits as $day => $slice) {
            if ($day >= $sparkStart && $day <= $today) {
                $evByDay[$day] = ($evByDay[$day] ?? 0) + $slice['kwh'];
            }
            if (substr($day, 0, 7) === $currentMonth) {
                $intMonthKwh  += $slice['kwh'];
                $intMonthCost += $slice['cost'];
                if (!$countedForMonth) { $intMonthCnt++; $countedForMonth = true; }
            }
            if ($day >= $prevStart && $day <= $prevEnd) {
                $intPrevKwh  += $slice['kwh'];
                $intPrevCost += $slice['cost'];
            }
            if ($day >= $lastYearStart && $day <= $lastYearEnd) {
                $intLYKwh  += $slice['kwh'];
                $intLYCost += $slice['cost'];
            }
        }
    }
    $intMonth = ['cnt' => $intMonthCnt, 'kwh' => $intMonthKwh, 'cost' => $intMonthCost];
    $intPrev  = ['kwh' => $intPrevKwh,  'cost' => $intPrevCost];
    $intLY    = ['kwh' => $intLYKwh,    'cost' => $intLYCost];

    // External charges — ISO 8601 Z-suffix prevents SQL date filtering, so we fetch
    // a bounded window (from same month last year onwards) and filter in PHP.
    $stmtExt = $chargesDb->prepare("SELECT datetime, kwh, pris FROM ext_charges WHERE datetime >= ?");
    $stmtExt->execute([$lastYearStart]);

    $sparkStartTs  = strtotime($sparkStart);
    $todayEndTs    = strtotime($today . ' 23:59:59');
    $prevStartTs   = strtotime($prevStart);
    $prevEndTs     = strtotime($prevEnd . ' 23:59:59');
    $lyStartTs     = strtotime($lastYearStart);
    $lyEndTs       = strtotime($lastYearEnd . ' 23:59:59');

    $extMonthCnt   = 0;
    $extMonthKwh   = 0.0;
    $extMonthCost  = 0.0;
    $extPrevKwh    = 0.0;
    $extPrevCost   = 0.0;
    $extLYKwh      = 0.0;
    $extLYCost     = 0.0;
    $extByDay      = [];

    foreach ($stmtExt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $t   = strtotime($row['datetime']);
        $day = date('Y-m-d', $t);
        if (date('Y-m', $t) === $currentMonth) {
            $extMonthCnt++;
            $extMonthKwh  += floatval($row['kwh']);
            $extMonthCost += floatval($row['pris']);
        }
        if ($t >= $prevStartTs && $t <= $prevEndTs) {
            $extPrevKwh  += floatval($row['kwh']);
            $extPrevCost += floatval($row['pris']);
        }
        if ($t >= $lyStartTs && $t <= $lyEndTs) {
            $extLYKwh  += floatval($row['kwh']);
            $extLYCost += floatval($row['pris']);
        }
        if ($t >= $sparkStartTs && $t <= $todayEndTs) {
            $extByDay[$day] = ($extByDay[$day] ?? 0) + floatval($row['kwh']);
        }
    }
    $extMonth = ['cnt' => $extMonthCnt, 'kwh' => $extMonthKwh, 'cost' => $extMonthCost];

    // =========================================================================
    // EV — sparkline: merge external charges into $evByDay (built above)
    // =========================================================================
    foreach ($extByDay as $day => $kwh) {
        $evByDay[$day] = ($evByDay[$day] ?? 0) + $kwh;
    }
    $evSparkline = [];
    $daysThisMonth = (int)date('d');
    for ($i = $daysThisMonth - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $evSparkline[] = round($evByDay[$day] ?? 0, 2);
    }

    // =========================================================================
    // Heatpump — current and previous month totals
    // =========================================================================
    $stmtHP = $powerlogDb->prepare("
        WITH daily_max AS (
            SELECT DATE(logdate)  AS day,
                   MAX(kwh)       AS max_kwh
            FROM powerlogjord
            WHERE DATE(logdate) >= DATE(?, '-1 day')
              AND DATE(logdate) <= ?
            GROUP BY DATE(logdate)
        ),
        daily_delta AS (
            SELECT day,
                   MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
            FROM daily_max
        )
        SELECT ROUND(SUM(delta_kwh), 2) AS total_kwh
        FROM daily_delta
        WHERE strftime('%Y-%m', day) = ?
    ");
    $stmtHP->execute([$sparkStart, $today, $currentMonth]);
    $hpCurrent = floatval($stmtHP->fetchColumn());

    $stmtHPPrev = $powerlogDb->prepare("
        WITH daily_max AS (
            SELECT DATE(logdate)  AS day,
                   MAX(kwh)       AS max_kwh
            FROM powerlogjord
            WHERE DATE(logdate) >= DATE(?, '-1 day')
              AND DATE(logdate) <= ?
            GROUP BY DATE(logdate)
        ),
        daily_delta AS (
            SELECT day,
                   MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
            FROM daily_max
        )
        SELECT ROUND(SUM(delta_kwh), 2) AS total_kwh
        FROM daily_delta
        WHERE DATE(day) BETWEEN ? AND ?
    ");
    $stmtHPPrev->execute([$prevStart, $prevEnd, $prevStart, $prevEnd]);
    $hpPrev = floatval($stmtHPPrev->fetchColumn());

    $hpPctChange = $hpPrev > 0
        ? round(($hpCurrent - $hpPrev) / $hpPrev * 100, 1)
        : null;

    $stmtHPYear = $powerlogDb->prepare("
        WITH daily_max AS (
            SELECT DATE(logdate)  AS day,
                   MAX(kwh)       AS max_kwh
            FROM powerlogjord
            WHERE DATE(logdate) >= DATE(?, '-1 day')
              AND DATE(logdate) <= ?
            GROUP BY DATE(logdate)
        ),
        daily_delta AS (
            SELECT day,
                   MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
            FROM daily_max
        )
        SELECT ROUND(SUM(delta_kwh), 2) AS total_kwh
        FROM daily_delta
        WHERE DATE(day) BETWEEN ? AND ?
    ");
    $stmtHPYear->execute([$lastYearStart, $lastYearEnd, $lastYearStart, $lastYearEnd]);
    $hpLastYear = floatval($stmtHPYear->fetchColumn());

    $hpPctChangeYear = $hpLastYear > 0
        ? round(($hpCurrent - $hpLastYear) / $hpLastYear * 100, 1)
        : null;

    // =========================================================================
    // Heatpump — 14-day sparkline
    // =========================================================================
    $stmtHPSpark = $powerlogDb->prepare("
        WITH daily_max AS (
            SELECT DATE(logdate)  AS day,
                   MAX(kwh)       AS max_kwh
            FROM powerlogjord
            WHERE DATE(logdate) >= DATE(?, '-1 day')
              AND DATE(logdate) <= ?
            GROUP BY DATE(logdate)
        ),
        daily_delta AS (
            SELECT day,
                   MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS kwh
            FROM daily_max
        )
        SELECT day, ROUND(kwh, 2) AS kwh
        FROM daily_delta
        WHERE DATE(day) BETWEEN ? AND ?
        ORDER BY day
    ");
    $stmtHPSpark->execute([$sparkStart, $today, $sparkStart, $today]);

    $hpByDay = [];
    foreach ($stmtHPSpark->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hpByDay[$row['day']] = floatval($row['kwh']);
    }
    $hpSparkline = [];
    for ($i = $daysThisMonth - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $hpSparkline[] = round($hpByDay[$day] ?? 0, 2);
    }

    // =========================================================================
    // House meter — current month, prev MTD, sparkline
    // Wrapped in try-catch: if powerloghus table not yet synced, hus = null.
    // =========================================================================
    $husCurrentKwh = null;
    $husPrevKwh    = null;
    $husLastYearKwh= null;
    $husSparkline  = null;

    try {
        $stmtHus = $powerlogDb->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)  AS day,
                       MAX(kwh)       AS max_kwh
                FROM powerloghus
                WHERE DATE(logdate) >= DATE(?, '-1 day')
                  AND DATE(logdate) <= ?
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
                FROM daily_max
            )
            SELECT ROUND(SUM(delta_kwh), 2) AS total_kwh
            FROM daily_delta
            WHERE strftime('%Y-%m', day) = ?
        ");
        $stmtHus->execute([$sparkStart, $today, $currentMonth]);
        $husCurrentKwh = floatval($stmtHus->fetchColumn());

        $stmtHusPrev = $powerlogDb->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)  AS day,
                       MAX(kwh)       AS max_kwh
                FROM powerloghus
                WHERE DATE(logdate) >= DATE(?, '-1 day')
                  AND DATE(logdate) <= ?
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
                FROM daily_max
            )
            SELECT ROUND(SUM(delta_kwh), 2) AS total_kwh
            FROM daily_delta
            WHERE DATE(day) BETWEEN ? AND ?
        ");
        $stmtHusPrev->execute([$prevStart, $prevEnd, $prevStart, $prevEnd]);
        $husPrevKwh = floatval($stmtHusPrev->fetchColumn());

        $stmtHusYear = $powerlogDb->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)  AS day,
                       MAX(kwh)       AS max_kwh
                FROM powerloghus
                WHERE DATE(logdate) >= DATE(?, '-1 day')
                  AND DATE(logdate) <= ?
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
                FROM daily_max
            )
            SELECT ROUND(SUM(delta_kwh), 2) AS total_kwh
            FROM daily_delta
            WHERE DATE(day) BETWEEN ? AND ?
        ");
        $stmtHusYear->execute([$lastYearStart, $lastYearEnd, $lastYearStart, $lastYearEnd]);
        $husLastYearKwh = floatval($stmtHusYear->fetchColumn());

        $stmtHusSpark = $powerlogDb->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)  AS day,
                       MAX(kwh)       AS max_kwh
                FROM powerloghus
                WHERE DATE(logdate) >= DATE(?, '-1 day')
                  AND DATE(logdate) <= ?
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS kwh
                FROM daily_max
            )
            SELECT day, ROUND(kwh, 2) AS kwh
            FROM daily_delta
            WHERE DATE(day) BETWEEN ? AND ?
            ORDER BY day
        ");
        $stmtHusSpark->execute([$sparkStart, $today, $sparkStart, $today]);

        $husByDay = [];
        foreach ($stmtHusSpark->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $husByDay[$row['day']] = floatval($row['kwh']);
        }
        $husSparkline = [];
        for ($i = $daysThisMonth - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $husSparkline[] = round($husByDay[$day] ?? 0, 2);
        }
    } catch (Exception $husEx) {
        // powerloghus not yet synced — hus fields remain null
        error_log('getDashboardSummary: house power query failed — ' . $husEx->getMessage());
    }

    // =========================================================================
    // Response
    // =========================================================================
    $totalKwh    = floatval($intMonth['kwh'])  + floatval($extMonth['kwh']);
    $totalCost   = floatval($intMonth['cost']) + floatval($extMonth['cost']);
    $daysInMonth = (int)date('t');

    $prevTotalCost = floatval($intPrev['cost']) + $extPrevCost;
    $lyTotalCost   = floatval($intLY['cost'])   + $extLYCost;

    $evCostPerKwh        = $totalKwh > 0      ? round($totalCost / $totalKwh, 3) : null;
    $evHomePct           = $totalKwh > 0      ? round(floatval($intMonth['kwh']) / $totalKwh * 100, 1) : null;
    $evProjected         = $daysThisMonth > 0 ? round($totalCost / $daysThisMonth * $daysInMonth) : null;
    $evPctChangeCost     = $prevTotalCost > 0 ? round(($totalCost - $prevTotalCost) / $prevTotalCost * 100, 1) : null;
    $evPctChangeCostYear = $lyTotalCost   > 0 ? round(($totalCost - $lyTotalCost)   / $lyTotalCost   * 100, 1) : null;

    $hpDailyAvg     = $daysThisMonth > 0 ? round($hpCurrent / $daysThisMonth, 2) : null;
    $hpProjected    = $daysThisMonth > 0 ? round($hpCurrent  / $daysThisMonth * $daysInMonth, 1) : null;

    $husDailyAvg    = ($husCurrentKwh !== null && $daysThisMonth > 0)
                        ? round($husCurrentKwh / $daysThisMonth, 2) : null;
    $husProjected   = ($husCurrentKwh !== null && $daysThisMonth > 0)
                        ? round($husCurrentKwh / $daysThisMonth * $daysInMonth, 1) : null;
    $husPctChange   = ($husCurrentKwh !== null && $husPrevKwh > 0)
                        ? round(($husCurrentKwh - $husPrevKwh) / $husPrevKwh * 100, 1) : null;
    $husPctChangeYear = ($husCurrentKwh !== null && $husLastYearKwh > 0)
                        ? round(($husCurrentKwh - $husLastYearKwh) / $husLastYearKwh * 100, 1) : null;

    $response = json_encode([
        'ev' => [
            'month_charges'        => intval($intMonth['cnt']) + intval($extMonth['cnt']),
            'month_kwh'            => round($totalKwh, 2),
            'month_cost'           => round($totalCost, 2),
            'cost_per_kwh'         => $evCostPerKwh,
            'home_kwh_pct'         => $evHomePct,
            'projected_cost'       => $evProjected,
            'pct_change_cost'      => $evPctChangeCost,
            'pct_change_year_cost' => $evPctChangeCostYear,
            'sparkline'            => $evSparkline,
        ],
        'heatpump' => [
            'month_kwh'           => round($hpCurrent, 2),
            'prev_month_kwh'      => round($hpPrev, 2),
            'last_year_month_kwh' => round($hpLastYear, 2),
            'pct_change'          => $hpPctChange,
            'pct_change_year'     => $hpPctChangeYear,
            'daily_avg_kwh'       => $hpDailyAvg,
            'projected_kwh'       => $hpProjected,
            'sparkline'           => $hpSparkline,
        ],
        'hus' => $husCurrentKwh !== null ? [
            'month_kwh'       => round($husCurrentKwh, 2),
            'pct_change'      => $husPctChange,
            'pct_change_year' => $husPctChangeYear,
            'daily_avg_kwh'   => $husDailyAvg,
            'projected_kwh'   => $husProjected,
            'sparkline'       => $husSparkline,
        ] : null,
    ]);
    @file_put_contents($_dashCacheFile, $response);
    echo $response;

} catch (Exception $e) {
    http_response_code(500);
    error_log("getDashboardSummary error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
