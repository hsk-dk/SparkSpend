<?php
/**
 * Monthly Bill Data API
 *
 * Returns monthly EV charging and heat pump consumption data for the last N months.
 *
 * GET params:
 *   months  (optional, default 24) — number of months to return
 */

// ─── 5-minute file cache (keyed by numMonths + date) ───────────────────────────
$_billNumMonths = max(1, min(60, intval($_GET['months'] ?? 24)));
$_billCacheKey  = 'bill_' . $_billNumMonths . '_' . date('Y-m-d');
$_cached = QueryBuilder::fileCacheRead($_billCacheKey, CacheHelper::TTL_MEDIUM);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

$numMonths  = $_billNumMonths;
$chargesDb  = DatabaseManager::getChargesDb();
$powerlogDb = DatabaseManager::getPowerlogDb();

// Build list of YYYY-MM strings from current month back N months.
$months = [];
$baseDate = date('Y-m') . '-01';
for ($i = 0; $i < $numMonths; $i++) {
    $months[] = date('Y-m', strtotime("-{$i} months", strtotime($baseDate)));
}
$oldest      = end($months);
$rangeStart  = $oldest . '-01';
$rangeEnd    = date('Y-m-t');

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
// EV — external charges
// =========================================================================
$extRows = QueryBuilder::getExternalCharges($chargesDb, $rangeStart);

$externalByMonth = [];
foreach ($extRows as $row) {
    $t     = strtotime($row['datetime']);
    $month = date('Y-m', $t);
    if (!isset($externalByMonth[$month])) {
        $externalByMonth[$month] = ['kwh' => 0.0, 'cost' => 0.0];
    }
    $externalByMonth[$month]['kwh']  += floatval($row['kwh']);
    $externalByMonth[$month]['cost'] += floatval($row['pris']);
}

// =========================================================================
// HP — monthly consumption from powerlogjord
// =========================================================================
$hpDeltaByMonth = QueryBuilder::lagDeltaByMonth($powerlogDb, 'powerlogjord', $rangeStart, $rangeEnd);
$hpByMonth = [];
foreach ($hpDeltaByMonth as $month => $kwh) {
    $hpByMonth[$month] = ['kwh' => $kwh];
}

// =========================================================================
// House — whole-house meter from SQLite powerloghus
// =========================================================================
$husByMonth = [];
try {
    $husDeltaByMonth = QueryBuilder::lagDeltaByMonth($powerlogDb, 'powerloghus', $rangeStart, $rangeEnd);
    foreach ($husDeltaByMonth as $month => $kwh) {
        $husByMonth[$month] = ['kwh' => $kwh];
    }
} catch (Exception $husEx) {
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
