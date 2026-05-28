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
 *   hus[YYYY-MM]     — {kwh} (powerloghus SQLite daily-delta sum, reset-safe; empty if table not yet synced)
 *   range            — {start: YYYY-MM-DD, end: YYYY-MM-DD} for elspot cost fetch
 *
 * GET params:
 *   months  (optional, default 24) — number of months to return
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

// ─── 5-minute file cache (keyed by numMonths + date) ───────────────────────────
$_billNumMonths = max(1, min(60, intval($_GET['months'] ?? 24)));
$_billCacheKey  = 'bill_' . $_billNumMonths . '_' . date('Y-m-d');
$_cached = QueryBuilder::fileCacheRead($_billCacheKey, 300);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

try {
    $numMonths  = $_billNumMonths;
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
    // EV — internal charges: raw fetch, proportional midnight-split by month
    // =========================================================================
    $stmtInt = $chargesDb->prepare("
        SELECT startedAt, stoppedAt,
               COALESCE(consumedKwh, 0) AS kwh,
               COALESCE(cost, 0) AS cost
        FROM charges
        WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
    ");
    $stmtInt->execute([$rangeEnd, $rangeStart]);
    $internalByMonth = [];
    foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $splits = QueryBuilder::splitChargeByDays(
            $row['startedAt'], $row['stoppedAt'],
            floatval($row['kwh']), floatval($row['cost'])
        );
        foreach ($splits as $day => $slice) {
            $month = substr($day, 0, 7);
            if ($month < $oldest) continue;
            if (!isset($internalByMonth[$month])) {
                $internalByMonth[$month] = ['kwh' => 0.0, 'cost' => 0.0];
            }
            $internalByMonth[$month]['kwh']  += $slice['kwh'];
            $internalByMonth[$month]['cost'] += $slice['cost'];
        }
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
    $hpDeltaByMonth = QueryBuilder::lagDeltaByMonth($powerlogDb, 'powerlogjord', $rangeStart, $rangeEnd);
    $hpByMonth = [];
    foreach ($hpDeltaByMonth as $month => $kwh) {
        $hpByMonth[$month] = ['kwh' => $kwh];
    }

    // =========================================================================
    // House — whole-house meter from SQLite powerloghus.
    // Data is normalised to kWh during the sync cron job, so only a simple
    // positive-clamp is needed here (no 500-cap required).
    // Gracefully skipped if the table has not been synced yet.
    // =========================================================================
    $husByMonth = [];
    try {
        $husDeltaByMonth = QueryBuilder::lagDeltaByMonth($powerlogDb, 'powerloghus', $rangeStart, $rangeEnd);
        foreach ($husDeltaByMonth as $month => $kwh) {
            $husByMonth[$month] = ['kwh' => $kwh];
        }
    } catch (Exception $husEx) {
        // powerloghus table not yet created / synced — hus data will be empty
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

    $json = json_encode([
        'months' => $months,
        'ev'     => $evByMonth,
        'hp'     => $hpByMonth,
        'hus'    => $husByMonth,
        'range'  => ['start' => $rangeStart, 'end' => $rangeEnd],
    ]);
    QueryBuilder::fileCacheWrite($_billCacheKey, $json);
    echo $json;

} catch (Exception $e) {
    http_response_code(500);
    error_log('getMonthlyBillData error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
