<?php
/**
 * Annual Summary API
 *
 * Returns per-year totals for EV (internal + external charges),
 * heatpump, and house consumption.
 *
 * Response shape:
 * {
 *   "years": [
 *     {
 *       "year": "2024",
 *       "ev":       { "charges": int, "kwh": float, "cost": float },
 *       "heatpump": { "kwh": float },
 *       "hus":      { "kwh": float } | null
 *     }, ...
 *   ]
 * }
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

// ─── 1-hour file cache ────────────────────────────────────────────────────────
$_annualCacheKey = 'annual_' . date('Y-m-d');
$_cached = QueryBuilder::fileCacheRead($_annualCacheKey, 3600);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

try {
    $chargesDb  = DatabaseManager::getChargesDb();
    $powerlogDb = DatabaseManager::getPowerlogDb();

    // =========================================================================
    // EV — internal charges: raw fetch, proportional midnight-split by year
    // =========================================================================
    $intByYear = [];
    $rows = $chargesDb->query("
        SELECT startedAt, stoppedAt,
               COALESCE(consumedKwh, 0) AS kwh,
               COALESCE(cost, 0) AS cost
        FROM charges
        WHERE startedAt IS NOT NULL AND stoppedAt IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $splits = QueryBuilder::splitChargeByDays(
            $row['startedAt'], $row['stoppedAt'],
            floatval($row['kwh']), floatval($row['cost'])
        );
        $countedYears = [];
        foreach ($splits as $day => $slice) {
            $year = substr($day, 0, 4);
            if (!isset($intByYear[$year])) {
                $intByYear[$year] = ['cnt' => 0, 'kwh' => 0.0, 'cost' => 0.0];
            }
            $intByYear[$year]['kwh']  += $slice['kwh'];
            $intByYear[$year]['cost'] += $slice['cost'];
            if (!in_array($year, $countedYears)) {
                $intByYear[$year]['cnt']++;
                $countedYears[] = $year;
            }
        }
    }
    foreach ($intByYear as &$data) {
        $data['kwh']  = round($data['kwh'], 2);
        $data['cost'] = round($data['cost'], 2);
    }
    unset($data);
    ksort($intByYear);

    // =========================================================================
    // EV — external charges (PHP-side year extraction: ISO 8601 Z-suffix on
    // older records may not parse reliably in SQLite strftime)
    // =========================================================================
    $extByYear = [];
    $extRows = $chargesDb->query("SELECT datetime, kwh, pris FROM ext_charges")
                          ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($extRows as $r) {
        $year = date('Y', strtotime($r['datetime']));
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
    // Using lagDeltaByYear over all available data (start date predates any readings)
    $allDataStart = '2000-01-01';
    $allDataEnd   = date('Y-m-d');

    $hpByYear = QueryBuilder::lagDeltaByYear($powerlogDb, 'powerlogjord', $allDataStart, $allDataEnd);

    // =========================================================================
    // House — same pattern, wrapped in try/catch (table may not exist yet)
    // =========================================================================
    $husByYear = null;
    try {
        $husByYear = QueryBuilder::lagDeltaByYear($powerlogDb, 'powerloghus', $allDataStart, $allDataEnd);
    } catch (Exception $husEx) {
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
    QueryBuilder::fileCacheWrite($_annualCacheKey, $response);
    echo $response;

} catch (\Throwable $e) {
    http_response_code(500);
    error_log('getAnnualSummary error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
