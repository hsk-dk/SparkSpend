<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Dashboard Summary API
 * Returns lightweight summary data for both EV and heatpump systems
 * for the current month + 14-day sparklines. The data assembly is DashboardSummary::build().
 */
return static function (Request $request): Response {
    // 5-minute file cache
    $key = 'dashboard_' . date('Y-m-d');
    if (($hit = ApiCache::fetch($key, CacheHelper::TTL_MEDIUM)) !== null) return Response::json($hit);

    return ApiCache::store($key, DashboardSummary::build(
        DatabaseManager::getChargesDb(),
        DatabaseManager::getPowerlogDb()
    ));
};
