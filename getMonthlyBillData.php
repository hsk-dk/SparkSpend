<?php
/**
 * Monthly Bill Data API
 *
 * Returns monthly EV charging and heat pump consumption data for the last N months.
 * Used by the Regning tab to help the user reconcile electricity bills by subtracting
 * EV and heat pump consumption from the total bill.
 *
 * Response structure:
 *   months[]         — list of YYYY-MM strings, newest first
 *   ev[YYYY-MM]      — {kwh, cost} (internal Monta + external charges combined)
 *   hp[YYYY-MM]      — {kwh} (powerlogjord daily-delta sum, reset-safe)
 *   hus[YYYY-MM]     — {kwh} (powerloghus daily-delta sum, reset-safe; empty if MySQL not configured)
 *   range            — {start: YYYY-MM-DD, end: YYYY-MM-DD} for elspot cost fetch
 *
 * GET params:
 *   months  (optional, default 24) — number of months to return
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/MySQLManager.php';

header('Content-Type: application/json');

try {
    $numMonths  = max(1, min(60, intval($_GET['months'] ?? 24)));
    $chargesDb  = DatabaseManager::getChargesDb();
    $powerlogDb = DatabaseManager::getPowerlogDb();

    // Build list of YYYY-MM strings from current month back N months
    $months = [];
    for ($i = 0; $i < $numMonths; $i++) {
        $months[] = date('Y-m', strtotime("-{$i} months"));
    }
    $oldest      = end($months); // e.g. '2024-03'
    $rangeStart  = $oldest . '-01';
    $rangeEnd    = date('Y-m-t'); // last day of current month

    // =========================================================================
    // EV — internal charges (consumedKwh + cost) grouped by month
    // =========================================================================
    $stmtInt = $chargesDb->prepare("
        SELECT strftime('%Y-%m', createdAt) as month,
               COALESCE(SUM(consumedKwh), 0) as kwh,
               COALESCE(SUM(cost), 0)        as cost
        FROM charges
        WHERE strftime('%Y-%m', createdAt) >= ?
        GROUP BY month
    ");
    $stmtInt->execute([$oldest]);
    $internalByMonth = [];
    foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $internalByMonth[$row['month']] = [
            'kwh'  => floatval($row['kwh']),
            'cost' => floatval($row['cost']),
        ];
    }

    // =========================================================================
    // EV — external charges (kwh + pris), PHP-side filtering due to Z suffix
    // =========================================================================
    $stmtExt = $chargesDb->prepare(
        "SELECT datetime, kwh, pris FROM ext_charges WHERE datetime >= ?"
    );
    // ISO string comparison works for YYYY-MM (the Z suffix doesn't matter here
    // because we only need month-level grouping via strtotime).
    $stmtExt->execute([$rangeStart]);

    $externalByMonth = [];
    foreach ($stmtExt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $t     = strtotime($row['datetime']);
        $month = date('Y-m', $t);
        if (!isset($externalByMonth[$month])) {
            $externalByMonth[$month] = ['kwh' => 0.0, 'cost' => 0.0];
        }
        $externalByMonth[$month]['kwh']  += floatval($row['kwh']);
        $externalByMonth[$month]['cost'] += floatval($row['pris']);
    }

    // =========================================================================
    // HP — monthly consumption from powerlogjord (cumulative kWh meter).
    // Uses day-by-day LAG deltas instead of MAX-MIN to avoid inflated values
    // when the meter resets mid-month (a reset makes MIN≈0 and MAX=pre-reset
    // peak, so simple MAX-MIN returns the entire meter's lifetime kWh).
    // Negative deltas (resets) are clamped to 0; only real consumption is summed.
    // The WITH query starts one day before $rangeStart so the first day of the
    // oldest month gets a valid prior-day reading for its delta.
    // =========================================================================
    $stmtHP = $powerlogDb->prepare("
        WITH daily_max AS (
            SELECT DATE(logdate)  AS day,
                   MAX(kwh)       AS max_kwh
            FROM powerlogjord
            WHERE DATE(logdate) >= DATE(?, '-1 day')
            GROUP BY DATE(logdate)
        ),
        daily_delta AS (
            SELECT day,
                   strftime('%Y-%m', day) AS month,
                   MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
            FROM daily_max
        )
        SELECT month,
               ROUND(SUM(delta_kwh), 2) AS total_kwh
        FROM daily_delta
        WHERE month >= ?
        GROUP BY month
    ");
    $stmtHP->execute([$rangeStart, $oldest]);
    $hpByMonth = [];
    foreach ($stmtHP->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hpByMonth[$row['month']] = ['kwh' => round(floatval($row['total_kwh']), 2)];
    }

    // =========================================================================
    // House — whole-house meter from MySQL powerloghus (cumulative kWh meter).
    // Same LAG-delta strategy as HP above: avoids inflated values on resets.
    // Gracefully skipped if MySQL credentials are not configured.
    // =========================================================================
    $husByMonth = [];
    try {
        $mysqlDb   = MySQLManager::getHeatpumpDb();
        $husTable  = $GLOBALS['mysqlHousePowerTable'] ?? 'powerloghus';
        $stmtHus   = $mysqlDb->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)  AS day,
                       MAX(kwh)       AS max_kwh
                FROM `{$husTable}`
                WHERE DATE(logdate) >= DATE_SUB(?, INTERVAL 1 DAY)
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       DATE_FORMAT(day, '%Y-%m') AS month,
                       -- Clamp: negative = meter reset (0), >500 = corrupt spike (0).
                       -- A real household day never exceeds ~200 kWh; 500 is a safe ceiling.
                       CASE
                           WHEN max_kwh - LAG(max_kwh) OVER (ORDER BY day) BETWEEN 0 AND 500
                           THEN max_kwh - LAG(max_kwh) OVER (ORDER BY day)
                           ELSE 0
                       END AS delta_kwh
                FROM daily_max
            )
            SELECT month,
                   ROUND(SUM(delta_kwh), 2) AS total_kwh
            FROM daily_delta
            WHERE month >= ?
            GROUP BY month
            ORDER BY month ASC
        ");
        $stmtHus->execute([$rangeStart, $oldest]);
        foreach ($stmtHus->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $husByMonth[$row['month']] = ['kwh' => round(floatval($row['total_kwh']), 2)];
        }
    } catch (Exception $husEx) {
        // MySQL not configured or table missing — hus data will be empty
        error_log('getMonthlyBillData: house power query failed — ' . $husEx->getMessage());
    }

    // =========================================================================
    // Assemble combined EV per month
    // =========================================================================
    $evByMonth = [];
    $allMonths  = array_unique(array_merge(
        array_keys($internalByMonth),
        array_keys($externalByMonth)
    ));
    foreach ($allMonths as $m) {
        $int = $internalByMonth[$m] ?? ['kwh' => 0.0, 'cost' => 0.0];
        $ext = $externalByMonth[$m] ?? ['kwh' => 0.0, 'cost' => 0.0];
        $evByMonth[$m] = [
            'kwh'  => round($int['kwh'] + $ext['kwh'], 2),
            'cost' => round($int['cost'] + $ext['cost'], 2),
        ];
    }

    echo json_encode([
        'months' => $months,
        'ev'     => $evByMonth,
        'hp'     => $hpByMonth,
        'hus'    => $husByMonth,
        'range'  => ['start' => $rangeStart, 'end' => $rangeEnd],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log('getMonthlyBillData error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
