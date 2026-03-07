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

    $stmtExt = $chargesDb->prepare("
        SELECT COUNT(*) as cnt,
               COALESCE(SUM(kwh), 0) as kwh,
               COALESCE(SUM(pris), 0) as cost
        FROM ext_charges
        WHERE strftime('%Y-%m', datetime) = ?
    ");
    $stmtExt->execute([$currentMonth]);
    $extMonth = $stmtExt->fetch(PDO::FETCH_ASSOC);

    // =========================================================================
    // EV — 14-day sparkline (internal + external merged by day)
    // =========================================================================
    $stmtSpark = $chargesDb->prepare("
        SELECT date(createdAt) as day, SUM(consumedKwh) as kwh
        FROM charges
        WHERE date(createdAt) BETWEEN ? AND ?
        GROUP BY day
        UNION ALL
        SELECT date(datetime) as day, SUM(kwh) as kwh
        FROM ext_charges
        WHERE date(datetime) BETWEEN ? AND ?
        GROUP BY day
    ");
    $stmtSpark->execute([$sparkStart, $today, $sparkStart, $today]);

    $evByDay = [];
    foreach ($stmtSpark->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $evByDay[$row['day']] = ($evByDay[$row['day']] ?? 0) + floatval($row['kwh']);
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
    echo json_encode([
        'ev' => [
            'month_charges' => intval($intMonth['cnt']) + intval($extMonth['cnt']),
            'month_kwh'     => round(floatval($intMonth['kwh']) + floatval($extMonth['kwh']), 2),
            'month_cost'    => round(floatval($intMonth['cost']) + floatval($extMonth['cost']), 2),
            'sparkline'     => $evSparkline,
        ],
        'heatpump' => [
            'month_kwh'      => round($hpCurrent, 2),
            'prev_month_kwh' => round($hpPrev, 2),
            'pct_change'     => $hpPctChange,
            'sparkline'      => $hpSparkline,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("getDashboardSummary error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
