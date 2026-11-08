<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * EV Year-over-Year Comparison API
 *
 * Returns monthly EV consumption (internal + external charges) grouped by
 * year and month.
 */

return static function (Request $request): Response {
    // ─── 1-hour file cache (resets at midnight) ───────────────────────────────────
    $cacheKey = 'ev_compare_' . date('Y-m-d');
    if (($hit = ApiCache::fetch($cacheKey, CacheHelper::TTL_LONG)) !== null) return Response::json($hit);
    // ─────────────────────────────────────────────────────────────────────────────

    $db = DatabaseManager::getChargesDb();

    // ['YYYY']['MM'] => ['kwh' => float, 'cost' => float]
    $byYearMonth = [];

    // =========================================================================
    // Internal charges — proportional day-split
    // =========================================================================
    $rows = $db->query("
        SELECT startedAt, stoppedAt,
               COALESCE(consumedKwh, 0) AS kwh,
               COALESCE(cost, 0)        AS cost
        FROM charges
        WHERE startedAt IS NOT NULL AND stoppedAt IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $splits = DateHelper::splitChargeByDays(
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
    // External charges
    // =========================================================================
    $extRows = ChargeRepository::getExternal($db);

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
                'year'       => (int) $year,
                'month'      => sprintf('%02d', (int) $month),   // always "01".."12" (array keys turn "12" into int 12)
                'total_kwh'  => round($data['kwh'],  2),
                'total_cost' => round($data['cost'], 2),
            ];
        }
    }

    return ApiCache::store($cacheKey, $result);
};
