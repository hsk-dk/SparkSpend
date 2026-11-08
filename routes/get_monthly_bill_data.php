<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Monthly Bill Data API
 *
 * Returns monthly EV charging and heat pump consumption data for the last N months.
 * The data assembly is MonthlyBill::build().
 *
 * GET params:
 *   months  (optional, default 24) — number of months to return
 */
return static function (Request $request): Response {
    // 5-minute file cache (keyed by numMonths + date)
    $numMonths = max(1, min(60, Http::int($request->query, 'months', 24)));
    $key       = 'bill_' . $numMonths . '_' . date('Y-m-d');
    if (($hit = ApiCache::fetch($key, CacheHelper::TTL_MEDIUM)) !== null) return Response::json($hit);

    $bill = MonthlyBill::build(DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb(), $numMonths);
    return ApiCache::store($key, $bill['payload'], $bill['complete']);
};
