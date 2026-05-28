<?php
/**
 * EV Year-over-Year Comparison API
 *
 * Returns monthly EV consumption (internal + external charges) grouped by
 * year and month. Used for the multi-year comparison chart in the Elbil section.
 *
 * Response shape:
 * [
 *   { "year": "2024", "month": "05", "total_kwh": 123.45, "total_cost": 456.78 },
 *   ...
 * ]
 * Sorted ascending by year, then month. Only months with at least one charge included.
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

// ─── 1-hour file cache (resets at midnight) ───────────────────────────────────
$_compareCacheKey = 'ev_compare_' . date('Y-m-d');
$_cached = QueryBuilder::fileCacheRead($_compareCacheKey, 3600);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

try {
    $db = DatabaseManager::getChargesDb();

    // ['YYYY']['MM'] => ['kwh' => float, 'cost' => float]
    $byYearMonth = [];

    // =========================================================================
    // Internal charges — proportional day-split so cross-midnight sessions
    // are attributed to the correct calendar month.
    // =========================================================================
    $rows = $db->query("
        SELECT startedAt, stoppedAt,
               COALESCE(consumedKwh, 0) AS kwh,
               COALESCE(cost, 0)        AS cost
        FROM charges
        WHERE startedAt IS NOT NULL AND stoppedAt IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $splits = QueryBuilder::splitChargeByDays(
            $row['startedAt'], $row['stoppedAt'],
            floatval($row['kwh']), floatval($row['cost'])
        );
        foreach ($splits as $day => $slice) {
            $year  = substr($day, 0, 4);
            $month = substr($day, 5, 2);
            if (!isset($byYearMonth[$year][$month])) {
                $byYearMonth[$year][$month] = ['kwh' => 0.0, 'cost' => 0.0];
            }
            $byYearMonth[$year][$month]['kwh']  += $slice['kwh'];
            $byYearMonth[$year][$month]['cost'] += $slice['cost'];
        }
    }

    // =========================================================================
    // External charges — datetime stored as ISO 8601 string; use PHP strtotime
    // to handle both Z-suffix and bare local timestamps safely.
    // =========================================================================
    $extRows = $db->query("SELECT datetime, kwh, pris FROM ext_charges")
                   ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($extRows as $r) {
        $t = strtotime($r['datetime']);
        if ($t === false) continue;
        $year  = date('Y', $t);
        $month = date('m', $t);
        if (!isset($byYearMonth[$year][$month])) {
            $byYearMonth[$year][$month] = ['kwh' => 0.0, 'cost' => 0.0];
        }
        $byYearMonth[$year][$month]['kwh']  += floatval($r['kwh']);
        $byYearMonth[$year][$month]['cost'] += floatval($r['pris']);
    }

    // =========================================================================
    // Flatten and sort
    // =========================================================================
    $result = [];
    ksort($byYearMonth);
    foreach ($byYearMonth as $year => $months) {
        ksort($months);
        foreach ($months as $month => $data) {
            $result[] = [
                'year'       => $year,
                'month'      => $month,
                'total_kwh'  => round($data['kwh'],  2),
                'total_cost' => round($data['cost'], 2),
            ];
        }
    }

    $response = json_encode($result);
    QueryBuilder::fileCacheWrite($_compareCacheKey, $response);
    echo $response;

} catch (\Throwable $e) {
    http_response_code(500);
    error_log('getChargeCompare error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
