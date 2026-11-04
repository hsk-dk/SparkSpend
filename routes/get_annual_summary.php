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
$_skipCache = false; // ?nocache removed: unauthenticated cache bypass was a cheap DoS lever
if (!$_skipCache) {
    ApiCache::serve($_annualCacheKey, CacheHelper::TTL_LONG);
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
$extRows = ChargeRepository::getExternal($chargesDb);
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

$hpByYear = PowerlogRepository::lagDeltaByYear($powerlogDb, 'powerlogjord', $allDataStart, $allDataEnd);

// =========================================================================
// House — same pattern, wrapped in try/catch (table may not exist yet)
// =========================================================================
$husByYear = null;
try {
    $husByYear = PowerlogRepository::lagDeltaByYear($powerlogDb, 'powerloghus', $allDataStart, $allDataEnd);
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

// Estimated VP / whole-house cost per year (elvarme model, server-side).
require_once __DIR__ . '/../includes/CostEstimates.php';
$_costsComplete = true;
try {
    $_annual = CostEstimates::annual($years, date('Y-m-d'));
    $_costsComplete = $_annual['complete'];
    foreach ($years as &$_y) {
        $_y['cost'] = $_annual['costs'][(string) $_y['year']] ?? null;
    }
    unset($_y);
} catch (\Throwable $e) {
    error_log('getAnnualSummary cost estimate: ' . $e->getMessage());
    $_costsComplete = false;
}

// Not stored when prices were incomplete (rate-limited) or ?nocache was used.
ApiCache::respond($_annualCacheKey, ['years' => $years], !$_skipCache && $_costsComplete);
