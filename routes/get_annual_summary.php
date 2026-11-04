<?php
/**
 * Annual Summary API
 *
 * Returns per-year totals for EV (internal + external charges), heatpump, and house
 * consumption plus estimated costs. The data assembly is AnnualSummary::build().
 */

// 1-hour file cache
$_annualCacheKey = 'annual_' . date('Y-m-d');
ApiCache::serve($_annualCacheKey, CacheHelper::TTL_LONG);

$_annual = AnnualSummary::build(DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb());
// Not stored when prices were incomplete (rate-limited).
ApiCache::respond($_annualCacheKey, $_annual['payload'], $_annual['complete']);
