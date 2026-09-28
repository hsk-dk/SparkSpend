<?php
/**
 * Get charge analytics and cost trend data
 *
 * Provides cost analysis by:
 * - Cost trends over time (daily/weekly/monthly grouping)
 * - Cost statistics (min/max/avg) for selected period
 * - Internal vs external charge breakdown
 * - Support for rolling date windows (28-day, 90-day, etc)
 *
 * GET parameters:
 * - filter: Vehicle ID filter (or 'all' for all vehicles)
 * - dateRange: Date range in format "YYYY-MM-DD til YYYY-MM-DD"
 * - groupBy: Time period grouping ('day', 'week', or 'month')
 */

$db = DatabaseManager::getChargesDb();

// Parse request parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$dateRange = isset($_GET['dateRange']) ? $_GET['dateRange'] : '';
$groupBy = isset($_GET['groupBy']) ? $_GET['groupBy'] : 'week';
if (!in_array($groupBy, ['day', 'week', 'month'])) {
    $groupBy = 'week';
}
$showZeroKwh = (isset($_GET['showZeroKwh']) && $_GET['showZeroKwh'] === 'true') ? true : false;

// ─── 5-minute file cache ──────────────────────────────────────────────────
$_analyticsCacheKey = 'analytics_' . md5($filter . '|' . $dateRange . '|' . $groupBy . '|' . ($showZeroKwh ? '1' : '0'));
$_cached = QueryBuilder::fileCacheRead($_analyticsCacheKey, CacheHelper::TTL_MEDIUM);
if ($_cached !== null) {
    echo $_cached;
    exit;
}
// ─────────────────────────────────────────────────────────────────────────

// Build filter array for QueryBuilder methods
$filters = [
    'groupBy' => $groupBy,
    'showZeroKwh' => $showZeroKwh
];

if ($filter !== 'all') {
    $filters['vehicleId'] = intval($filter);
}

if (!empty($dateRange)) {
    $filters['dateRange'] = $dateRange;
}

// Get cost trend data (internal + external breakdown)
$costTrend = QueryBuilder::getCostTrend($db, $filters);

// Get cost statistics for the period
$costStats = QueryBuilder::getCostStatistics($db, $filters);

// Group trend data by internal/external
$trendBySource = [
    'internal' => [],
    'external' => [],
    'combined_daily' => []
];

// Process trend data and combine to daily totals
$dailyTotals = [];
foreach ($costTrend as $record) {
    $date = $record['date'];
    $source = $record['source'];

    // Store by source
    $trendBySource[$source][] = $record;

    // Accumulate daily totals
    if (!isset($dailyTotals[$date])) {
        $dailyTotals[$date] = [
            'date' => $date,
            'internal_kwh' => 0,
            'internal_cost' => 0,
            'internal_charges' => 0,
            'external_kwh' => 0,
            'external_cost' => 0,
            'external_charges' => 0,
            'total_kwh' => 0,
            'total_cost' => 0,
            'total_charges' => 0,
            'avg_cost_per_kwh' => 0
        ];
    }

    if ($source === 'internal') {
        $dailyTotals[$date]['internal_kwh'] += floatval($record['total_kwh']);
        $dailyTotals[$date]['internal_cost'] += floatval($record['total_cost']);
        $dailyTotals[$date]['internal_charges'] += intval($record['charge_count']);
    } else {
        $dailyTotals[$date]['external_kwh'] += floatval($record['total_kwh']);
        $dailyTotals[$date]['external_cost'] += floatval($record['total_cost']);
        $dailyTotals[$date]['external_charges'] += intval($record['charge_count']);
    }
}

// Calculate combined totals and average cost per kWh
foreach ($dailyTotals as &$dailyData) {
    $dailyData['total_kwh'] = $dailyData['internal_kwh'] + $dailyData['external_kwh'];
    $dailyData['total_cost'] = $dailyData['internal_cost'] + $dailyData['external_cost'];
    $dailyData['total_charges'] = $dailyData['internal_charges'] + $dailyData['external_charges'];
    $dailyData['avg_cost_per_kwh'] = $dailyData['total_kwh'] > 0
        ? round($dailyData['total_cost'] / $dailyData['total_kwh'], 3)
        : 0;
}
unset($dailyData);

// Sort by date ascending for correct chart axis order
ksort($dailyTotals);

$response = json_encode([
    'trend' => [
        'by_source' => $trendBySource,
        'daily_totals' => array_values($dailyTotals)
    ],
    'statistics' => $costStats,
    'meta' => [
        'filter' => $filter,
        'dateRange' => $dateRange,
        'groupBy' => $groupBy
    ]
]);
QueryBuilder::fileCacheWrite($_analyticsCacheKey, $response);
echo $response;
