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

header('Content-Type: application/json');

// ─── 1-hour file cache ────────────────────────────────────────────────────────
// Key includes today's date so cache resets at midnight automatically.
$_cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_annual_' . date('Y-m-d') . '.json';
if (file_exists($_cacheFile) && (time() - filemtime($_cacheFile)) < 3600) {
    echo file_get_contents($_cacheFile);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

try {
    $chargesDb  = DatabaseManager::getChargesDb();
    $powerlogDb = DatabaseManager::getPowerlogDb();

    // =========================================================================
    // EV — internal charges grouped by year
    // =========================================================================
    $intByYear = [];
    $rows = $chargesDb->query("
        SELECT strftime('%Y', createdAt) AS year,
               COUNT(*) AS cnt,
               ROUND(SUM(consumedKwh), 2) AS kwh,
               ROUND(SUM(cost), 2) AS cost
        FROM charges
        GROUP BY year
        ORDER BY year
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $intByYear[$r['year']] = [
            'cnt'  => intval($r['cnt']),
            'kwh'  => floatval($r['kwh']),
            'cost' => floatval($r['cost']),
        ];
    }

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
    $hpByYear = [];
    $hpRows = $powerlogDb->query("
        WITH daily_max AS (
            SELECT DATE(logdate) AS day,
                   MAX(kwh)      AS max_kwh
            FROM powerlogjord
            GROUP BY DATE(logdate)
        ),
        daily_delta AS (
            SELECT day,
                   MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
            FROM daily_max
        )
        SELECT strftime('%Y', day) AS year,
               ROUND(SUM(delta_kwh), 2) AS kwh
        FROM daily_delta
        GROUP BY strftime('%Y', day)
        ORDER BY year
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($hpRows as $r) {
        $hpByYear[$r['year']] = floatval($r['kwh']);
    }

    // =========================================================================
    // House — same pattern, wrapped in try/catch (table may not exist yet)
    // =========================================================================
    $husByYear = null;
    try {
        $husByYear = [];
        $husRows = $powerlogDb->query("
            WITH daily_max AS (
                SELECT DATE(logdate) AS day,
                       MAX(kwh)      AS max_kwh
                FROM powerloghus
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
                FROM daily_max
            )
            SELECT strftime('%Y', day) AS year,
                   ROUND(SUM(delta_kwh), 2) AS kwh
            FROM daily_delta
            GROUP BY strftime('%Y', day)
            ORDER BY year
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($husRows as $r) {
            $husByYear[$r['year']] = floatval($r['kwh']);
        }
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
    @file_put_contents($_cacheFile, $response);
    echo $response;

} catch (Exception $e) {
    http_response_code(500);
    error_log('getAnnualSummary error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
