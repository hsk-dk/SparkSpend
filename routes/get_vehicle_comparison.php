<?php
/**
 * Get vehicle cost and efficiency comparison
 *
 * GET parameters:
 * - dateRange: Date range in format "YYYY-MM-DD til YYYY-MM-DD" (optional)
 * - sortBy: Sort column (default: 'total_cost')
 */

$db = DatabaseManager::getChargesDb();

// Parse request parameters
$dateRange = isset($_GET['dateRange']) ? $_GET['dateRange'] : '';
$sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'total_cost';

// Validate sortBy to prevent injection
$validSortColumns = ['total_cost', 'avg_cost_per_kwh', 'total_kwh', 'charge_count'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'total_cost';
}

// Build filter array
$filters = [];
if (!empty($dateRange)) {
    $filters['dateRange'] = $dateRange;
}

// ─── 5-minute file cache ──────────────────────────────────────────────────
$_cacheKey = 'vehicle_compare_' . md5($dateRange . '|' . $sortBy);
$_cached   = QueryBuilder::fileCacheRead($_cacheKey, CacheHelper::TTL_MEDIUM);
if ($_cached !== null) {
    echo $_cached;
    exit;
}
$vehicleComparison = QueryBuilder::getVehicleCostComparison($db, $filters);

// Sort data based on sortBy parameter
usort($vehicleComparison, function($a, $b) use ($sortBy) {
    $valA = $a[$sortBy];
    $valB = $b[$sortBy];

    // Descending order for all columns
    if ($valA == $valB) return 0;
    return ($valA > $valB) ? -1 : 1;
});

$response = json_encode([
    'vehicles' => $vehicleComparison,
    'meta' => [
        'dateRange' => $dateRange,
        'sortBy' => $sortBy,
        'count' => count($vehicleComparison)
    ]
]);
QueryBuilder::fileCacheWrite($_cacheKey, $response);
echo $response;
