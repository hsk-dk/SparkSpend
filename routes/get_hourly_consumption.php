<?php
/**
 * Hourly consumption for the cost estimate.
 *
 * GET api.php?action=hourly-consumption&start=YYYY-MM-DD&end=YYYY-MM-DD
 *
 * Returns, per local hour, the heat pump (vp) and "rest" (house - heat pump -
 * home EV charging) kWh. The client weights the hourly spot prices with these,
 * so the estimate reflects WHEN the electricity was used instead of a plain
 * average of the month's prices.
 *
 *   { "start": "...", "end": "...", "hours": ["2026-10-01 00", ...],
 *     "vp": [0.41, ...], "rest": [0.62, ...] }      // arrays aligned with "hours"
 */
require_once __DIR__ . '/../includes/HourlyConsumption.php';

$start = isset($_GET['start']) ? trim($_GET['start']) : '';
$end   = isset($_GET['end'])   ? trim($_GET['end'])   : '';

if (!Http::isDate($start) || !Http::isDate($end) || $end < $start
    || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 62) {
    Http::fail('start and end must be valid YYYY-MM-DD dates, end >= start, at most 62 days apart');
}

$_cacheKey = 'hourlycons_' . $start . '_' . $end;
ApiCache::serve($_cacheKey, CacheHelper::TTL_MEDIUM);

$response = json_encode(HourlyConsumption::series(
    DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb(), $start, $end
));
ApiCache::respond($_cacheKey, $response);
