<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * House Power Consumption API
 *
 * Returns daily or monthly house electricity consumption from powerloghus,
 * combined with heat pump (powerlogjord) and EV charging breakdowns.
 * The data assembly is HousePower (includes/HousePower.php).
 *
 * GET parameters:
 *   mode   — 'daily', 'monthly', 'compare', 'ytd', or 'sync_status'
 *   month  — YYYY-MM  (required for daily mode)
 *   year   — YYYY     (required for monthly mode)
 */

$powerlogDb = DatabaseManager::getPowerlogDb();
$chargesDb  = DatabaseManager::getChargesDb();
$mode = $_GET['mode'] ?? 'daily';

// Sync status changes frequently: never cached.
if ($mode === 'sync_status') {
    Http::send(HousePower::syncStatus($powerlogDb));
    return;
}

// 1-hour file cache
$_hwCacheKey = 'housepower_' . md5($_SERVER['QUERY_STRING'] ?? '');
ApiCache::serve($_hwCacheKey, CacheHelper::TTL_LONG);

if ($mode === 'daily') {
    $month = Http::query('month', date('Y-m'));
    if (!Http::isMonth($month)) {
        Http::fail('Invalid month format. Use YYYY-MM.');
    }
    $result = HousePower::daily($powerlogDb, $chargesDb, $month);
} elseif ($mode === 'monthly') {
    $year = Http::query('year', date('Y'));
    if (!Http::isYear($year)) {
        Http::fail('Invalid year format. Use YYYY.');
    }
    $result = HousePower::monthly($powerlogDb, $chargesDb, $year);
} elseif ($mode === 'compare') {
    $result = HousePower::compare($powerlogDb);
} elseif ($mode === 'ytd') {
    $result = HousePower::ytd($powerlogDb);
} else {
    Http::fail('Unknown mode. Use daily, monthly, compare, ytd, or sync_status.');
}

ApiCache::respond($_hwCacheKey, $result['payload'], $result['complete']);
