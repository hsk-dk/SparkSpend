<?php
/**
 * Dashboard Summary API
 * Returns lightweight summary data for both EV and heatpump systems
 * for the current month + 14-day sparklines. The data assembly is DashboardSummary::build().
 */

// 5-minute file cache
$_dashCacheKey = 'dashboard_' . date('Y-m-d');
ApiCache::serve($_dashCacheKey, CacheHelper::TTL_MEDIUM);

ApiCache::respond($_dashCacheKey, DashboardSummary::build(
    DatabaseManager::getChargesDb(),
    DatabaseManager::getPowerlogDb()
));
