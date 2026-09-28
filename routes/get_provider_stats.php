<?php
/**
 * Get external charge statistics grouped by provider
 *
 * GET parameters:
 * - filter: vehicleId or 'all' (optional, default: 'all')
 * - dateRange: Date range in format "YYYY-MM-DD til YYYY-MM-DD" (optional)
 */

// ─── 5-minute file cache ─────────────────────────────────────────────────────────────
$_provCacheKey = 'providerstats_' . md5(($_GET['filter'] ?? '') . '|' . ($_GET['dateRange'] ?? ''));
$_cached = QueryBuilder::fileCacheRead($_provCacheKey, CacheHelper::TTL_MEDIUM);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

$db = DatabaseManager::getChargesDb();

$vehicleId = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$dateRange = isset($_GET['dateRange']) ? trim($_GET['dateRange']) : '';

// Parse date range
$dateFilters = null;
if (!empty($dateRange)) {
    try {
        $dateFilters = QueryBuilder::parseDateRange($dateRange);
    } catch (Exception $parseEx) {
        // Invalid format — ignore filter, return all data
    }
}

// Build SQL query with date filtering
$where  = ["e.kwh > 0"];
$params = [];

if ($vehicleId !== 'all') {
    $where[]  = "e.vehicleId = ?";
    $params[] = intval($vehicleId);
}
if ($dateFilters) {
    $where[]  = "DATE(e.datetime) >= ?";
    $where[]  = "DATE(e.datetime) <= ?";
    $params[] = $dateFilters['start'];
    $params[] = $dateFilters['end'];
}

$whereStr = implode(" AND ", $where);
$stmt = $db->prepare("
    SELECT e.kwh, e.pris,
           COALESCE(p.providerName, 'Ukendt') AS providerName
    FROM ext_charges e
    LEFT JOIN providers p ON e.providerId = p.id
    WHERE {$whereStr}
");
$stmt->execute($params);

$map = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $name = $row['providerName'];
    if (!isset($map[$name])) {
        $map[$name] = [
            'providerName' => $name,
            'charge_count' => 0,
            'total_kwh'    => 0.0,
            'total_cost'   => 0.0,
        ];
    }
    $map[$name]['charge_count']++;
    $map[$name]['total_kwh']  += floatval($row['kwh']);
    $map[$name]['total_cost'] += floatval($row['pris']);
}

$result = [];
foreach ($map as $entry) {
    $result[] = [
        'providerName'     => $entry['providerName'],
        'charge_count'     => $entry['charge_count'],
        'total_kwh'        => round($entry['total_kwh'], 2),
        'total_cost'       => round($entry['total_cost'], 2),
        'avg_cost_per_kwh' => $entry['total_kwh'] > 0
            ? round($entry['total_cost'] / $entry['total_kwh'], 3)
            : 0,
    ];
}

// Sort by avg_cost_per_kwh ascending (cheapest first)
usort($result, fn($a, $b) => $a['avg_cost_per_kwh'] <=> $b['avg_cost_per_kwh']);

http_response_code(200);
$json = json_encode($result);
QueryBuilder::fileCacheWrite($_provCacheKey, $json);
echo $json;
