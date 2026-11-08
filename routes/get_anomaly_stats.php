<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Anomaly Detection API
 *
 * Compares current-month-to-date daily average kWh against the same calendar-month period
 * last year for Hus (whole-house meter), Jordvarme (heat pump) and El-bil. The detection
 * itself is AnomalyDetector::detect().
 */
return static function (Request $request): Response {
    // 5-minute file cache (keyed by date)
    $key = 'anomaly_' . date('Y-m-d');
    if (($hit = ApiCache::fetch($key, CacheHelper::TTL_MEDIUM)) !== null) return Response::json($hit);

    return ApiCache::store($key, AnomalyDetector::detect(
        DatabaseManager::getChargesDb(),
        DatabaseManager::getPowerlogDb()
    ));
};
