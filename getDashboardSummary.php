<?php
/**
 * Dashboard Summary API
 * Returns lightweight summary data for both EV and heatpump systems
 * for the current month + 14-day sparklines.
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';

header('Content-Type: application/json');

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
    // EV — current month totals (internal + external)
    // =========================================================================
    $stmtInt = $chargesDb->prepare("
        SELECT COUNT(*) as cnt,
               COALESCE(SUM(consumedKwh), 0) as kwh,
               COALESCE(SUM(cost), 0) as cost
        FROM charges
        WHERE strftime('%Y-%m', createdAt) = ?
    ");
    $stmtInt->execute([$currentMonth]);
    $intMonth = $stmtInt->fetch(PDO::FETCH_ASSOC);

    // External charges — fetch all and filter in PHP.
    // ext_charges.datetime is stored as ISO 8601 with Z suffix (e.g. 2024-12-07T15:37:00Z)
    // which SQLite's strftime/date functions cannot parse reliably.
    $stmtExt = $chargesDb->prepare("SELECT datetime, kwh, pris FROM ext_charges");
    $stmtExt->execute();

    $sparkStartTs  = strtotime($sparkStart);
    $todayEndTs    = strtotime($today . ' 23:59:59');
    $extMonthCnt   = 0;
    $extMonthKwh   = 0.0;
    $extMonthCost  = 0.0;
    $extByDay      = [];

    foreach ($stmtExt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $t   = strtotime($row['datetime']);
        $day = date('Y-m-d', $t);
        if (date('Y-m', $t) === $currentMonth) {
            $extMonthCnt++;
            $extMonthKwh  += floatval($row['kwh']);
            $extMonthCost += floatval($row['pris']);
        }
        if ($t >= $sparkStartTs && $t <= $todayEndTs) {
            $extByDay[$day] = ($extByDay[$day] ?? 0) + floatval($row['kwh']);
        }
    }
    $extMonth = ['cnt' => $extMonthCnt, 'kwh' => $extMonthKwh, 'cost' => $extMonthCost];

    // =========================================================================
    // EV — 14-day sparkline (internal via SQL, external merged from PHP above)
    // =========================================================================
    $stmtSpark = $chargesDb->prepare("
        SELECT date(createdAt) as day, SUM(consumedKwh) as kwh
        FROM charges
        WHERE date(createdAt) BETWEEN ? AND ?
        GROUP BY day
    ");
    $stmtSpark->execute([$sparkStart, $today]);

    $evByDay = [];
    foreach ($stmtSpark->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $evByDay[$row['day']] = floatval($row['kwh']);
    }
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
        SELECT (MAX(kwh) - MIN(kwh)) as total_kwh
        FROM powerlogjord
        WHERE strftime('%Y-%m', logdate) = ?
    ");
    $stmtHP->execute([$currentMonth]);
    $hpCurrent = floatval($stmtHP->fetchColumn());

    $stmtHPPrev = $powerlogDb->prepare("
        SELECT (MAX(kwh) - MIN(kwh)) as total_kwh
        FROM powerlogjord
        WHERE DATE(logdate) BETWEEN ? AND ?
    ");
    $stmtHPPrev->execute([$prevStart, $prevEnd]);
    $hpPrev = floatval($stmtHPPrev->fetchColumn());

    $hpPctChange = $hpPrev > 0
        ? round(($hpCurrent - $hpPrev) / $hpPrev * 100, 1)
        : null;

    $stmtHPYear = $powerlogDb->prepare("
        SELECT (MAX(kwh) - MIN(kwh)) as total_kwh
        FROM powerlogjord
        WHERE DATE(logdate) BETWEEN ? AND ?
    ");
    $stmtHPYear->execute([$lastYearStart, $lastYearEnd]);
    $hpLastYear = floatval($stmtHPYear->fetchColumn());

    $hpPctChangeYear = $hpLastYear > 0
        ? round(($hpCurrent - $hpLastYear) / $hpLastYear * 100, 1)
        : null;

    // =========================================================================
    // Heatpump — 14-day sparkline
    // =========================================================================
    $stmtHPSpark = $powerlogDb->prepare("
        SELECT DATE(logdate) as day, (MAX(kwh) - MIN(kwh)) as kwh
        FROM powerlogjord
        WHERE DATE(logdate) BETWEEN ? AND ?
        GROUP BY day
        ORDER BY day
    ");
    $stmtHPSpark->execute([$sparkStart, $today]);

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
    // Response
    // =========================================================================
    $totalKwh    = floatval($intMonth['kwh'])  + floatval($extMonth['kwh']);
    $totalCost   = floatval($intMonth['cost']) + floatval($extMonth['cost']);
    $daysInMonth = (int)date('t');

    $evCostPerKwh   = $totalKwh > 0     ? round($totalCost / $totalKwh, 3) : null;
    $evHomePct      = $totalKwh > 0     ? round(floatval($intMonth['kwh']) / $totalKwh * 100, 1) : null;
    $evProjected    = $daysThisMonth > 0 ? round($totalCost / $daysThisMonth * $daysInMonth) : null;

    $hpDailyAvg     = $daysThisMonth > 0 ? round($hpCurrent / $daysThisMonth, 2) : null;
    $hpProjected    = $daysThisMonth > 0 ? round($hpCurrent  / $daysThisMonth * $daysInMonth, 1) : null;

    echo json_encode([
        'ev' => [
            'month_charges'  => intval($intMonth['cnt']) + intval($extMonth['cnt']),
            'month_kwh'      => round($totalKwh, 2),
            'month_cost'     => round($totalCost, 2),
            'cost_per_kwh'   => $evCostPerKwh,
            'home_kwh_pct'   => $evHomePct,
            'projected_cost' => $evProjected,
            'sparkline'      => $evSparkline,
        ],
        'heatpump' => [
            'month_kwh'       => round($hpCurrent, 2),
            'prev_month_kwh'  => round($hpPrev, 2),
            'pct_change'      => $hpPctChange,
            'pct_change_year' => $hpPctChangeYear,
            'daily_avg_kwh'   => $hpDailyAvg,
            'projected_kwh'   => $hpProjected,
            'sparkline'       => $hpSparkline,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("getDashboardSummary error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
