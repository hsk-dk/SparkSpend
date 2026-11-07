<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Anomaly Detection API
 *
 * Compares current-month-to-date daily average kWh against the same calendar-month period
 * last year for Hus (whole-house meter), Jordvarme (heat pump) and El-bil. The detection
 * itself is AnomalyDetector::detect().
 */

// 5-minute file cache (keyed by date)
$_cacheKey = 'anomaly_' . date('Y-m-d');
ApiCache::serve($_cacheKey, CacheHelper::TTL_MEDIUM);

ApiCache::respond($_cacheKey, AnomalyDetector::detect(
    DatabaseManager::getChargesDb(),
    DatabaseManager::getPowerlogDb()
));
