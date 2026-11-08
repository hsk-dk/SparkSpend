<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Annual Summary API
 *
 * Returns per-year totals for EV (internal + external charges), heatpump, and house
 * consumption plus estimated costs. The data assembly is AnnualSummary::build().
 */
return static function (Request $request): Response {
    // 1-hour file cache
    $key = 'annual_' . date('Y-m-d');
    if (($hit = ApiCache::fetch($key, CacheHelper::TTL_LONG)) !== null) return Response::json($hit);

    $annual = AnnualSummary::build(DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb());
    // Not stored when prices were incomplete (rate-limited).
    return ApiCache::store($key, $annual['payload'], $annual['complete']);
};
