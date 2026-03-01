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
        $whereConditions2[] = "date(createdAt) >= ?";
        $whereConditions2[] = "date(createdAt) <= ?";
    }
    if ($filter !== 'all') {
        $whereConditions2[] = "vehicleId = ?";
    }

    $query2 = "SELECT vehicleId, createdAt as datetime, consumedKwh as kwh, cost as pris FROM charges";
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
    $whereConditions3 = [];
    if ($dateFilters) {
        $whereConditions3[] = "date(datetime) >= ?";
        $whereConditions3[] = "date(datetime) <= ?";
    }
    if ($filter !== 'all') {
        $whereConditions3[] = "vehicleId = ?";
    }

    $query3 = "SELECT vehicleId, datetime, kwh, pris FROM ext_charges";
    if (!empty($whereConditions3)) {
        $query3 .= " WHERE " . implode(" AND ", $whereConditions3);
    }

    $stmt3 = $db->prepare($query3);
    $params3 = [];
    if ($dateFilters) {
        $params3[] = $dateFilters['start'];
        $params3[] = $dateFilters['end'];
    }
    if ($filter !== 'all') {
        $params3[] = intval($filter);
    }
    $stmt3->execute($params3);
    $extCharges = $stmt3->fetchAll(PDO::FETCH_ASSOC) ?? [];

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
     * Find the most recent charge before a given timestamp for a vehicle
     * This ensures we match the correct charge with the km driven after that charge
     *
     * @param int $vehicleId Vehicle ID
     * @param int $timestamp Timestamp to search before
     * @param array $chargesByVehicle Charges grouped by vehicle (must be sorted by time)
     * @return array|null Most recent charge before timestamp, or null if none found
     */
    $findChargeBeforeTime = function($vehicleId, $timestamp, $chargesByVehicle) {
        if (!isset($chargesByVehicle[$vehicleId])) {
            return null;
        }

        // Find the most recent charge that happened BEFORE this timestamp
        $mostRecentBefore = null;
        $maxTime = -PHP_INT_MAX;

        foreach ($chargesByVehicle[$vehicleId] as $charge) {
            // Only consider charges that happened at or before the timestamp
            if ($charge['datetime'] <= $timestamp && $charge['datetime'] > $maxTime) {
                $maxTime = $charge['datetime'];
                $mostRecentBefore = $charge;
            }
        }

        return $mostRecentBefore;
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
            $kmDriven = $charge['odometer'] - $vehicleCharges[$index - 1]['odometer'];

            // Find the charging session that provided energy for this trip
            // Match the charge that happened BEFORE this odometer reading
            $previousCharge = $findChargeBeforeTime($vehicleId, $vehicleCharges[$index - 1]['cablePluggedInAt'], $chargesByVehicle);

            // Only calculate efficiency if we have valid data
            if ($previousCharge && $kmDriven > 0) {
                $kwh = $previousCharge['kwh'];
                $pris = $previousCharge['pris'];

                // Validate data: check for zero or negative values
                if ($kwh <= 0) {
                    // Skip charges with zero or negative kWh - they're data errors
                    continue;
                }

                if ($kmDriven < 0) {
                    // Odometer went backwards - likely vehicle reset or data error
                    error_log("Warning: Negative km for vehicle $vehicleId: $kmDriven km (odometer went backwards)");
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

                // Log genuinely suspicious values (anomalies only, not normal EV performance)
                // Normal EV efficiency: 4-20 km/kWh depending on conditions
                // Only log if outside realistic range
                if ($kmPerKwh > 25 || $kmPerKwh < 0.5) {
                    error_log("ANOMALY: Vehicle $vehicleId: $kmDriven km, $kwh kWh = $kmPerKwh km/kWh (charge from " . date('Y-m-d H:i', $previousCharge['datetime']) . ", odometer at " . date('Y-m-d H:i', $vehicleCharges[$index - 1]['cablePluggedInAt']) . ")");
                }

                // Update running totals for cumulative efficiency
                $runningKm[$vehicleId] += $kmDriven;
                $runningKwh[$vehicleId] += $kwh;
                $runningPris[$vehicleId] += $pris;

                // Calculate cumulative average efficiency
                $totalKmPerKwh = $runningKwh[$vehicleId] > 0 ? round($runningKm[$vehicleId] / $runningKwh[$vehicleId], 2) : null;
                $totalKrPerKm = $runningKm[$vehicleId] > 0 ? round($runningPris[$vehicleId] / $runningKm[$vehicleId], 2) : null;

                // Store results with both trip-specific and cumulative efficiency
                $results[] = [
                    'vehicleId' => $vehicleId,
                    'timestamp' => $timestamp,
                    'date' => date('Y-m-d H:i', $timestamp),
                    'kmDriven' => $kmDriven,
                    'totalKm' => $runningKm[$vehicleId],
                    'kwh' => $kwh,
                    'kmPerKwh' => $kmPerKwh,           // Trip-specific efficiency
                    'krPerKm' => $krPerKm,              // Trip-specific cost efficiency
                    'totalKmPerKwh' => $totalKmPerKwh,  // Cumulative average
                    'totalKrPerKm' => $totalKrPerKm     // Cumulative average
                ];
            }
        }
    }

    echo json_encode($results);

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

