<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Charging optimisation: what the home charges cost at the hourly prices and what the same
 * energy would have cost in the cheapest hours the car was available for.
 * The calculation is ChargeOptimization::build() / ChargeOptimizer.
 *
 * GET parameters:
 *   months  (optional, 1-12, default 3) - how far back to look
 */
return static function (Request $request): Response {
    $months = max(1, min(12, Http::int($request->query, 'months', 3)));
    $key = 'chargeopt_' . $months . '_' . date('Y-m-d-H') . '_' . Config::evFlexHours() . '_' . Config::evMaxKw();
    if (($hit = ApiCache::fetch($key, CacheHelper::TTL_LONG)) !== null) return Response::json($hit);

    $opt = ChargeOptimization::build(DatabaseManager::getChargesDb(), $months);
    return ApiCache::store($key, $opt['payload'], $opt['complete']);
};
