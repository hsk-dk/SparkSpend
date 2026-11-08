<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * House Power Consumption API
 *
 * Returns daily or monthly house electricity consumption from powerloghus,
 * combined with heat pump (powerlogjord) and EV charging breakdowns.
 * The data assembly is HousePower (includes/HousePower.php).
 *
 * GET parameters:
 *   mode   — 'daily', 'monthly', 'compare', 'ytd', or 'sync_status'
 *   month  — YYYY-MM  (required for daily mode)
 *   year   — YYYY     (required for monthly mode)
 */
return static function (Request $request): Response {
    $powerlogDb = DatabaseManager::getPowerlogDb();
    $chargesDb  = DatabaseManager::getChargesDb();
    $mode = $request->query['mode'] ?? 'daily';

    // Sync status changes frequently: never cached.
    if ($mode === 'sync_status') {
        return Response::json(HousePower::syncStatus($powerlogDb));
    }

    // 1-hour file cache
    $key = 'housepower_' . md5($request->server['QUERY_STRING'] ?? '');
    if (($hit = ApiCache::fetch($key, CacheHelper::TTL_LONG)) !== null) return Response::json($hit);

    if ($mode === 'daily') {
        $month = $request->param('month', date('Y-m'));
        if (!Http::isMonth($month)) {
            Http::fail('Invalid month format. Use YYYY-MM.');
        }
        $result = HousePower::daily($powerlogDb, $chargesDb, $month);
    } elseif ($mode === 'monthly') {
        $year = $request->param('year', date('Y'));
        if (!Http::isYear($year)) {
            Http::fail('Invalid year format. Use YYYY.');
        }
        $result = HousePower::monthly($powerlogDb, $chargesDb, $year);
    } elseif ($mode === 'compare') {
        $result = HousePower::compare($powerlogDb);
    } elseif ($mode === 'ytd') {
        $result = HousePower::ytd($powerlogDb);
    } else {
        Http::fail('Unknown mode. Use daily, monthly, compare, ytd, or sync_status.');
    }

    return ApiCache::store($key, $result['payload'], $result['complete']);
};
