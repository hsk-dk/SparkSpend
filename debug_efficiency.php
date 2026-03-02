<?php
/**
 * Debug script to analyze km/kWh calculation issues
 * Examines sample data to understand the current logic and identify problems
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';

header('Content-Type: application/json');

try {
    $db = DatabaseManager::getChargesDb();

    echo "=== EFFICIENCY CALCULATION DEBUG ===\n\n";

    // Get a sample vehicle with odometer data
    $query = "SELECT DISTINCT vehicleId FROM vehicle_charges LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $sampleVehicleId = $result['vehicleId'];

    echo "Sample Vehicle ID: $sampleVehicleId\n\n";

    // Fetch vehicle_charges (odometer readings)
    echo "--- ODOMETER READINGS (vehicle_charges) ---\n";
    $query = "SELECT id, vehicleId, cablePluggedInAt, odometer
              FROM vehicle_charges
              WHERE vehicleId = ?
              ORDER BY cablePluggedInAt
              LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute([$sampleVehicleId]);
    $vehicleCharges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($vehicleCharges as $index => $row) {
        echo sprintf(
            "[%d] Time: %s, Odometer: %d km\n",
            $index,
            $row['cablePluggedInAt'],
            $row['odometer']
        );
    }

    echo "\n--- CHARGES FOR SAME VEHICLE (consumption data) ---\n";
    $query = "SELECT id, createdAt, consumedKwh, cost
              FROM charges
              WHERE vehicleId = ?
              ORDER BY createdAt
              LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute([$sampleVehicleId]);
    $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($charges as $index => $row) {
        echo sprintf(
            "[%d] Time: %s, kWh: %.2f, Cost: %.2f\n",
            $index,
            $row['createdAt'],
            $row['consumedKwh'],
            $row['cost']
        );
    }

    // Analyze the matching logic
    echo "\n--- ANALYSIS OF KM/KWH CALCULATION ---\n";
    echo "Current logic (from code review):\n";
    echo "1. For each odometer reading, calculate km driven since last reading\n";
    echo "2. Find the 'nearest charge' to the PREVIOUS odometer's timestamp\n";
    echo "3. Calculate km/kWh = km_driven / kwh_from_nearest_charge\n\n";

    echo "POTENTIAL ISSUE:\n";
    echo "The 'nearest charge' logic might be pairing the wrong consumption with km driven.\n";
    echo "Should be: km driven FROM odometer[n-1] TO odometer[n] used energy FROM charge[?]\n";
    echo "But which charge? The one BEFORE odometer[n-1] or the one at odometer[n]?\n\n";

    // Calculate what the current code would do for first pair
    if (count($vehicleCharges) >= 2) {
        $odo1 = $vehicleCharges[0];
        $odo2 = $vehicleCharges[1];
        $kmDriven = $odo2['odometer'] - $odo1['odometer'];

        echo "Example: First two odometer readings\n";
        echo "  Odometer[0]: " . $odo1['cablePluggedInAt'] . " = " . $odo1['odometer'] . " km\n";
        echo "  Odometer[1]: " . $odo2['cablePluggedInAt'] . " = " . $odo2['odometer'] . " km\n";
        echo "  KM driven: $kmDriven km\n\n";

        // Find nearest charge to odo1's timestamp
        echo "Current logic finds 'nearest charge' to: " . $odo1['cablePluggedInAt'] . "\n";

        $odo1Timestamp = strtotime($odo1['cablePluggedInAt']);
        $nearestDiff = PHP_INT_MAX;
        $nearestCharge = null;

        foreach ($charges as $charge) {
            $chargeTime = strtotime($charge['createdAt']);
            $diff = abs($chargeTime - $odo1Timestamp);
            if ($diff < $nearestDiff) {
                $nearestDiff = $diff;
                $nearestCharge = $charge;
            }
        }

        if ($nearestCharge) {
            echo "  Found charge at: " . $nearestCharge['createdAt'] . "\n";
            echo "  Difference: " . ($nearestDiff / 60) . " minutes\n";
            echo "  kWh from that charge: " . $nearestCharge['consumedKwh'] . "\n";
            if ($nearestCharge['consumedKwh'] > 0) {
                $kmPerKwh = $kmDriven / $nearestCharge['consumedKwh'];
                echo "  Calculated km/kWh: " . round($kmPerKwh, 2) . "\n\n";

                echo "QUESTION: Is this correct?\n";
                echo "  - Did the vehicle drive $kmDriven km using " . $nearestCharge['consumedKwh'] . " kWh?\n";
                echo "  - Or is the nearest charge not the right one?\n";
            }
        }
    }

    echo "\n--- RECOMMENDATION ---\n";
    echo "Need to understand the temporal relationship:\n";
    echo "  1. Vehicle charges at time T1, consuming X kWh\n";
    echo "  2. Vehicle drives and odometer increases by Y km\n";
    echo "  3. Vehicle charges again at time T3, consuming Z kWh\n\n";
    echo "The Y km driven was enabled by the X kWh charged.\n";
    echo "So: km/kWh = Y / X\n";
    echo "This means: km measured at T3 minus km measured at T1 = Y\n";
    echo "And: kWh from charge at T1 = X\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
