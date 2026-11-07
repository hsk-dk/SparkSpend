<?php
/**
 * Base load per month (see includes/BaseLoad.php).
 *
 * GET api.php?action=base-load[&months=24]
 *   { months: [{month, median, min, max, days}], latest: {watts, kwh_per_month, days, from, to}|null, params }
 */
$months = min(60, max(3, Http::int($_GET, 'months', 24)));
$today  = date('Y-m-d');

$_cacheKey = 'baseload_' . $months . '_' . $today;
ApiCache::serve($_cacheKey, CacheHelper::TTL_MEDIUM);

$response = json_encode(BaseLoad::build(
    DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb(), $months, $today
));
ApiCache::respond($_cacheKey, $response);
