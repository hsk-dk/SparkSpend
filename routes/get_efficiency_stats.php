<?php
/**
 * Get efficiency statistics for vehicles
 *
 * Calculates efficiency metrics (km/kWh and kr/km) based on:
 * - Vehicle odometer readings
 * - Charging data (internal and external)
 *
 * GET parameters:
 * - filter: Vehicle ID filter (or 'all' for all vehicles)
 * - dateRange: Date range in format "YYYY-MM-DD til YYYY-MM-DD"
 */

// ─── 5-minute file cache ─────────────────────────────────────────────────────────────
$_effCacheKey = 'efficiency_' . md5(($_GET['filter'] ?? '') . '|' . ($_GET['dateRange'] ?? ''));
$_cached = QueryBuilder::fileCacheRead($_effCacheKey, 300);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

$db = DatabaseManager::getChargesDb();

// Parse request parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$dateRange = isset($_GET['dateRange']) ? $_GET['dateRange'] : '';

// Parse date range using QueryBuilder
$dateFilters = null;
if ($dateRange) {
    try {
        $dateFilters = QueryBuilder::parseDateRange($dateRange);
    } catch (\Throwable $e) {
        // Single-date or malformed range — ignore silently, show all data
        $dateFilters = null;
    }
}

// Build WHERE clause for vehicle_charges
$whereConditions = [];

if ($dateFilters) {
    $whereConditions[] = "date(cablePluggedInAt) >= ?";
    $whereConditions[] = "date(cablePluggedInAt) <= ?";
}

if ($filter !== 'all') {
    $whereConditions[] = "vehicleId = ?";
}

// Fetch vehicle_charges within the selected period
$query = "SELECT vehicleId, cablePluggedInAt, odometer FROM vehicle_charges";
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(" AND ", $whereConditions);
}
$query .= " ORDER BY vehicleId, cablePluggedInAt";

$stmt = $db->prepare($query);

// Build parameters
$params = [];
if ($dateFilters) {
    $params[] = $dateFilters['start'];
    $params[] = $dateFilters['end'];
}
if ($filter !== 'all') {
    $params[] = intval($filter);
}

$stmt->execute($params);
$vehicleCharges = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

// Convert datetime strings to timestamps
foreach ($vehicleCharges as &$row) {
    $row['cablePluggedInAt'] = strtotime($row['cablePluggedInAt']);
}

// Fetch internal charges (charges table)
$whereConditions2 = [];
if ($dateFilters) {
    $whereConditions2[] = "date(stoppedAt) >= ?";
    $whereConditions2[] = "date(stoppedAt) <= ?";
}
if ($filter !== 'all') {
    $whereConditions2[] = "vehicleId = ?";
}

$query2 = "SELECT vehicleId, stoppedAt as datetime, consumedKwh as kwh, cost as pris FROM charges";
if (!empty($whereConditions2)) {
    $query2 .= " WHERE " . implode(" AND ", $whereConditions2);
}

$stmt2 = $db->prepare($query2);
$params2 = [];
if ($dateFilters) {
    $params2[] = $dateFilters['start'];
    $params2[] = $dateFilters['end'];
}
if ($filter !== 'all') {
    $params2[] = intval($filter);
}
$stmt2->execute($params2);
$charges = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?? [];

// Fetch external charges (ext_charges table)
$extCharges = QueryBuilder::getExternalCharges(
    $db,
    $dateFilters ? $dateFilters['start'] : null,
    $dateFilters ? $dateFilters['end'] : null,
    $filter !== 'all' ? intval($filter) : null
);

// Combine all charges
$allCharges = [];

foreach ($charges as $row) {
    if (empty($row['datetime'])) continue;
    $row['datetime'] = strtotime($row['datetime']);
    if ($row['datetime'] === false) continue;
    $allCharges[] = $row;
}

foreach ($extCharges as $row) {
    if (empty($row['datetime'])) continue;
    $row['datetime'] = strtotime($row['datetime']);
    if ($row['datetime'] === false) continue;
    $allCharges[] = $row;
}

// Sort by timestamp
usort($allCharges, function($a, $b) {
    return $a['datetime'] - $b['datetime'];
});

// Build lookup map for faster charge access
$chargesByVehicle = [];
foreach ($allCharges as $charge) {
    if (!isset($chargesByVehicle[$charge['vehicleId']])) {
        $chargesByVehicle[$charge['vehicleId']] = [];
    }
    $chargesByVehicle[$charge['vehicleId']][] = $charge;
}

/**
 * Find and sum all charges between two timestamps for a vehicle
 */
$sumChargesBetween = function($vehicleId, $timestampAfter, $timestampBefore, $chargesByVehicle) {
    $total_kwh = 0;
    $total_pris = 0;

    if (!isset($chargesByVehicle[$vehicleId])) {
        return ['total_kwh' => 0, 'total_pris' => 0];
    }

    foreach ($chargesByVehicle[$vehicleId] as $charge) {
        if ($charge['datetime'] > $timestampAfter && $charge['datetime'] <= $timestampBefore) {
            $total_kwh += $charge['kwh'];
            $total_pris += $charge['pris'];
        }
    }

    return ['total_kwh' => $total_kwh, 'total_pris' => $total_pris];
};

// Calculate efficiency statistics
$results = [];
$runningKm = [];
$runningKwh = [];
$runningPris = [];

foreach ($vehicleCharges as $index => $charge) {
    $vehicleId = $charge['vehicleId'] ?? null;
    $timestamp = $charge['cablePluggedInAt'] ?? null;

    if (!$vehicleId || !$timestamp) {
        continue;
    }

    // Calculate km driven since last measurement for same vehicle
    if ($index > 0 && $vehicleCharges[$index - 1]['vehicleId'] == $vehicleId) {
        $prevOdometerTime = $vehicleCharges[$index - 1]['cablePluggedInAt'];
        $currentOdometerTime = $timestamp;
        $kmDriven = $charge['odometer'] - $vehicleCharges[$index - 1]['odometer'];

        // Sum all charges that happened between the two odometer readings
        $chargeData = $sumChargesBetween($vehicleId, $prevOdometerTime, $currentOdometerTime, $chargesByVehicle);
        $kwh = $chargeData['total_kwh'];
        $pris = $chargeData['total_pris'];

        // Odometer went backwards - vehicle reset or data error
        if ($kmDriven < 0) {
            error_log("Warning: Negative km for vehicle $vehicleId: $kmDriven km (odometer went backwards)");
            continue;
        }

        // Only calculate efficiency if we have valid data
        if ($kwh > 0 && $kmDriven > 0) {
            // Skip very short trips (< 20 km)
            if ($kmDriven < 20) {
                continue;
            }

            // Initialize running totals for this vehicle if needed
            if (!isset($runningKm[$vehicleId])) {
                $runningKm[$vehicleId] = 0;
                $runningKwh[$vehicleId] = 0;
                $runningPris[$vehicleId] = 0;
            }

            // Calculate efficiency for this trip
            $kmPerKwh = round($kmDriven / $kwh, 2);
            $krPerKm = round($pris / $kmDriven, 2);

            // Update running totals for cumulative efficiency
            $runningKm[$vehicleId] += $kmDriven;
            $runningKwh[$vehicleId] += $kwh;
            $runningPris[$vehicleId] += $pris;

            // Calculate cumulative average efficiency
            $totalKmPerKwh = $runningKwh[$vehicleId] > 0 ? round($runningKm[$vehicleId] / $runningKwh[$vehicleId], 2) : null;
            $totalKrPerKm = $runningKm[$vehicleId] > 0 ? round($runningPris[$vehicleId] / $runningKm[$vehicleId], 2) : null;

            // Store results with cumulative efficiency only
            $results[] = [
                'vehicleId' => $vehicleId,
                'timestamp' => $timestamp,
                'date' => date('Y-m-d H:i', $timestamp),
                'totalKm' => $runningKm[$vehicleId],
                'totalKwh' => $runningKwh[$vehicleId],
                'totalKmPerKwh' => $totalKmPerKwh,
                'totalKrPerKm' => $totalKrPerKm
            ];
        }
    }
}

$json = json_encode($results);
QueryBuilder::fileCacheWrite($_effCacheKey, $json);
echo $json;
