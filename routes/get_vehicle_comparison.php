<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Get vehicle cost and efficiency comparison
 *
 * GET parameters:
 * - dateRange: Date range in format "YYYY-MM-DD til YYYY-MM-DD" (optional)
 * - sortBy: Sort column (default: 'total_cost')
 */
return static function (Request $request): Response {
    $db = DatabaseManager::getChargesDb();

    // Parse request parameters
    $dateRange = isset($request->query['dateRange']) ? $request->query['dateRange'] : '';
    $sortBy = isset($request->query['sortBy']) ? $request->query['sortBy'] : 'total_cost';

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
    $key = 'vehicle_compare_' . md5($dateRange . '|' . $sortBy);
    if (($hit = ApiCache::fetch($key, CacheHelper::TTL_MEDIUM)) !== null) return Response::json($hit);
    $vehicleComparison = ChargeRepository::getVehicleCostComparison($db, $filters);

    // Sort data based on sortBy parameter
    usort($vehicleComparison, function($a, $b) use ($sortBy) {
        $valA = $a[$sortBy];
        $valB = $b[$sortBy];

        // Descending order for all columns
        if ($valA == $valB) return 0;
        return ($valA > $valB) ? -1 : 1;
    });

    return ApiCache::store($key, [
        'vehicles' => $vehicleComparison,
        'meta' => [
            'dateRange' => $dateRange,
            'sortBy' => $sortBy,
            'count' => count($vehicleComparison)
        ]
    ]);
};
