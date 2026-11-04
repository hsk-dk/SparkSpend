<?php
/**
 * Monthly Bill Data API
 *
 * Returns monthly EV charging and heat pump consumption data for the last N months.
 * The data assembly is MonthlyBill::build().
 *
 * GET params:
 *   months  (optional, default 24) — number of months to return
 */

// 5-minute file cache (keyed by numMonths + date)
$_billNumMonths = max(1, min(60, Http::int($_GET, 'months', 24)));
$_billCacheKey  = 'bill_' . $_billNumMonths . '_' . date('Y-m-d');
ApiCache::serve($_billCacheKey, CacheHelper::TTL_MEDIUM);

$_bill = MonthlyBill::build(DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb(), $_billNumMonths);
ApiCache::respond($_billCacheKey, $_bill['payload'], $_bill['complete']);
