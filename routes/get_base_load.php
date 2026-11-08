<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Base load per month (see includes/BaseLoad.php).
 *
 * GET api.php?action=base-load[&months=24]
 *   { months: [{month, median, min, max, days}], latest: {watts, kwh_per_month, days, from, to}|null, params }
 */
return static function (Request $request): Response {
    $months = min(60, max(3, Http::int($request->query, 'months', 24)));
    $today  = date('Y-m-d');

    $key = 'baseload_' . $months . '_' . $today;
    if (($hit = ApiCache::fetch($key, CacheHelper::TTL_MEDIUM)) !== null) return Response::json($hit);

    return ApiCache::store($key, BaseLoad::build(
        DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb(), $months, $today
    ));
};
