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
 *   hp[YYYY-MM]      — {kwh} (powerlog MAX-MIN per month)
 *   range            — {start: YYYY-MM-DD, end: YYYY-MM-DD} for elspot cost fetch
 *
 * GET params:
 *   months  (optional, default 24) — number of months to return
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';

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
    // HP — monthly consumption (MAX(kwh) - MIN(kwh) from powerlogjord)
    // =========================================================================
    $stmtHP = $powerlogDb->prepare("
        SELECT strftime('%Y-%m', logdate) as month,
               (MAX(kwh) - MIN(kwh)) as total_kwh
        FROM powerlogjord
        WHERE strftime('%Y-%m', logdate) >= ?
        GROUP BY month
    ");
    $stmtHP->execute([$oldest]);
    $hpByMonth = [];
    foreach ($stmtHP->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hpByMonth[$row['month']] = ['kwh' => round(floatval($row['total_kwh']), 2)];
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
        'range'  => ['start' => $rangeStart, 'end' => $rangeEnd],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log('getMonthlyBillData error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
