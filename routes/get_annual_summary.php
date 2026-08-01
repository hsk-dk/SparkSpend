<?php
/**
 * Annual Summary API
 *
 * Returns per-year totals for EV (internal + external charges),
 * heatpump, and house consumption.
 *
 * Note: This handler has its own bootstrap error handling since it loads
 * requires in a special order. When included from api.php, the requires
 * are already loaded so the try/catch below is a no-op safety net.
 */

// ─── 1-hour file cache ────────────────────────────────────────────────────────
$_annualCacheKey = 'annual_' . date('Y-m-d');
$_skipCache = !empty($_GET['nocache']);
if (!$_skipCache) {
    $_cached = QueryBuilder::fileCacheRead($_annualCacheKey, 3600);
    if ($_cached !== null) { echo $_cached; exit; }
}
// ─────────────────────────────────────────────────────────────────────────────

$chargesDb  = DatabaseManager::getChargesDb();
$powerlogDb = DatabaseManager::getPowerlogDb();

// =========================================================================
// EV — internal charges: SQL aggregation by year (using stoppedAt date).
// For annual totals, the midnight-split precision is unnecessary — at most
// 1-2 charges per year cross the Dec 31 → Jan 1 boundary.
// =========================================================================
$intByYear = [];
$stmt = $chargesDb->query("
    SELECT strftime('%Y', stoppedAt) AS year,
           COUNT(*)                   AS cnt,
           COALESCE(SUM(consumedKwh), 0) AS kwh,
           COALESCE(SUM(cost), 0)        AS cost
    FROM charges
    WHERE startedAt IS NOT NULL AND stoppedAt IS NOT NULL
    GROUP BY strftime('%Y', stoppedAt)
    ORDER BY year
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $intByYear[$row['year']] = [
        'cnt'  => intval($row['cnt']),
        'kwh'  => round(floatval($row['kwh']), 2),
        'cost' => round(floatval($row['cost']), 2),
    ];
}
ksort($intByYear);

// =========================================================================
// EV — external charges
// =========================================================================
$extByYear = [];
$extRows = QueryBuilder::getExternalCharges($chargesDb);
foreach ($extRows as $r) {
    $year = substr($r['datetime'], 0, 4);
    if (!isset($extByYear[$year])) {
        $extByYear[$year] = ['cnt' => 0, 'kwh' => 0.0, 'cost' => 0.0];
    }
    $extByYear[$year]['cnt']++;
    $extByYear[$year]['kwh']  += floatval($r['kwh']);
    $extByYear[$year]['cost'] += floatval($r['pris']);
}

// =========================================================================
// Heatpump — LAG-based daily delta, summed by year
// =========================================================================
$allDataStart = '2000-01-01';
$allDataEnd   = date('Y-m-d');

$hpByYear = QueryBuilder::lagDeltaByYear($powerlogDb, 'powerlogjord', $allDataStart, $allDataEnd);

// =========================================================================
// House — same pattern, wrapped in try/catch (table may not exist yet)
// =========================================================================
$husByYear = null;
try {
    $husByYear = QueryBuilder::lagDeltaByYear($powerlogDb, 'powerloghus', $allDataStart, $allDataEnd);
} catch (\Throwable $husEx) {
    $husByYear = null;
    error_log('getAnnualSummary: house query failed — ' . $husEx->getMessage());
}

// =========================================================================
// Merge all year keys and assemble response
// =========================================================================
$allYears = array_unique(array_merge(
    array_keys($intByYear),
    array_keys($extByYear),
    array_keys($hpByYear),
    $husByYear !== null ? array_keys($husByYear) : []
));
sort($allYears);

$years = [];
foreach ($allYears as $year) {
    $int = $intByYear[$year] ?? ['cnt' => 0, 'kwh' => 0.0, 'cost' => 0.0];
    $ext = $extByYear[$year] ?? ['cnt' => 0, 'kwh' => 0.0, 'cost' => 0.0];
    $hp  = $hpByYear[$year]  ?? 0.0;

    $years[] = [
        'year'     => $year,
        'ev'       => [
            'charges' => $int['cnt'] + $ext['cnt'],
            'kwh'     => round($int['kwh'] + $ext['kwh'], 2),
            'cost'    => round($int['cost'] + $ext['cost'], 2),
        ],
        'heatpump' => ['kwh' => $hp],
        'hus'      => $husByYear !== null
            ? ['kwh' => $husByYear[$year] ?? 0.0]
            : null,
    ];
}

$response = json_encode(['years' => $years]);
if (!$_skipCache) {
    QueryBuilder::fileCacheWrite($_annualCacheKey, $response);
}
echo $response;
