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

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

// ─── 5-minute file cache ─────────────────────────────────────────────────────────────
$_effCacheKey = 'efficiency_' . md5(($_GET['filter'] ?? '') . '|' . ($_GET['dateRange'] ?? ''));
$_cached = QueryBuilder::fileCacheRead($_effCacheKey, 300);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

try {
    $db = DatabaseManager::getChargesDb();

    // Parse request parameters
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $dateRange = isset($_GET['dateRange']) ? $_GET['dateRange'] : '';

    // Parse date range using QueryBuilder
    $dateFilters = null;
    if ($dateRange) {
        try {
            $dateFilters = QueryBuilder::parseDateRange($dateRange);
        } catch (Exception $e) {
            error_log("Invalid date range in getEfficiencyStats: " . $e->getMessage());
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
    // Note: Don't filter by date in SQL because ext_charges.datetime uses ISO 8601 format
    // with timezone (e.g., 2024-12-07T15:37:00Z) which SQLite's date() function doesn't parse
    // correctly. We'll filter in PHP after converting timestamps.
    $whereConditions3 = [];
    if ($filter !== 'all') {
        $whereConditions3[] = "vehicleId = ?";
    }

    $query3 = "SELECT vehicleId, datetime, kwh, pris FROM ext_charges";
    if (!empty($whereConditions3)) {
        $query3 .= " WHERE " . implode(" AND ", $whereConditions3);
    }

    $stmt3 = $db->prepare($query3);
    $params3 = [];
    if ($filter !== 'all') {
        $params3[] = intval($filter);
    }
    $stmt3->execute($params3);
    $extChargesRaw = $stmt3->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Filter external charges by date in PHP
    $extCharges = [];
    if ($dateFilters) {
        $startTime = strtotime($dateFilters['start']);
        $endTime = strtotime($dateFilters['end'] . ' 23:59:59');

        foreach ($extChargesRaw as $charge) {
            $chargeTime = strtotime($charge['datetime']);
            if ($chargeTime >= $startTime && $chargeTime <= $endTime) {
                $extCharges[] = $charge;
            }
        }
    } else {
        $extCharges = $extChargesRaw;
    }

    // Combine all charges
    $allCharges = [];

    foreach ($charges as $row) {
        $row['datetime'] = strtotime($row['datetime']);
        $allCharges[] = $row;
    }

    foreach ($extCharges as $row) {
        $row['datetime'] = strtotime($row['datetime']);
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
     * This ensures we account for ALL energy consumed during the trip
     *
     * @param int $vehicleId Vehicle ID
     * @param int $timestampAfter Start of period (exclusive)
     * @param int $timestampBefore End of period (inclusive)
     * @param array $chargesByVehicle Charges grouped by vehicle
     * @return array Array with 'total_kwh' and 'total_pris' for the period
     */
    $sumChargesBetween = function($vehicleId, $timestampAfter, $timestampBefore, $chargesByVehicle) {
        $total_kwh = 0;
        $total_pris = 0;

        if (!isset($chargesByVehicle[$vehicleId])) {
            return ['total_kwh' => 0, 'total_pris' => 0];
        }

        // Sum all charges that happened AFTER the first timestamp and AT OR BEFORE the second timestamp
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

        // Skip if missing required data
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
                // Skip very short trips (< 20 km) - these are likely charging session artifacts
                // not real driving trips
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

                // Store results with cumulative efficiency only (per-trip values are unreliable)
                $results[] = [
                    'vehicleId' => $vehicleId,
                    'timestamp' => $timestamp,
                    'date' => date('Y-m-d H:i', $timestamp),
                    'totalKm' => $runningKm[$vehicleId],
                    'totalKwh' => $runningKwh[$vehicleId],
                    'totalKmPerKwh' => $totalKmPerKwh,  // Cumulative average - reliable
                    'totalKrPerKm' => $totalKrPerKm     // Cumulative average - reliable
                ];
            }
        }
    }

    $json = json_encode($results);
    QueryBuilder::fileCacheWrite($_effCacheKey, $json);
    echo $json;

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in getEfficiencyStats.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in getEfficiencyStats.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>

