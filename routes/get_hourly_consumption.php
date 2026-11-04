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

$validDate = static function (string $d): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
};
if (!$validDate($start) || !$validDate($end) || $end < $start
    || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 62) {
    http_response_code(400);
    echo json_encode(['error' => 'start and end must be valid YYYY-MM-DD dates, end >= start, at most 62 days apart']);
    exit;
}

$_cacheKey = 'hourlycons_' . $start . '_' . $end;
$_cached = QueryBuilder::fileCacheRead($_cacheKey, CacheHelper::TTL_MEDIUM);
if ($_cached !== null) { echo $_cached; exit; }

$response = json_encode(HourlyConsumption::series(
    DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb(), $start, $end
));
QueryBuilder::fileCacheWrite($_cacheKey, $response);
echo $response;
