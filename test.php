<?php
// Forbind til SQLite-database
$db = new SQLite3('data/charging_data.db');

// Hent vehicle_charges
$query = "SELECT vehicleId, cablePluggedInAt, odometer, chargeType FROM vehicle_charges ORDER BY vehicleId, cablePluggedInAt";
$vehicleChargesResult = $db->query($query);

// Hent all_charges
$query = "SELECT vehicleId, datetime, kwh, pris FROM all_charges ORDER BY vehicleId, datetime";
$allChargesResult = $db->query($query);

// Konverter SQLite resultater til arrays
$vehicleCharges = [];
while ($row = $vehicleChargesResult->fetchArray(SQLITE3_ASSOC)) {
    $row['cablePluggedInAt'] = strtotime($row['cablePluggedInAt']); // Konverter tid til timestamp
    $vehicleCharges[] = $row;
}

$allCharges = [];
while ($row = $allChargesResult->fetchArray(SQLITE3_ASSOC)) {
    $row['datetime'] = strtotime($row['datetime']); // Konverter tid til timestamp
    $allCharges[] = $row;
}

// Luk databasen
$db->close();

// Funktion til at finde den tætteste opladningstid i all_charges
function findNearestCharge($vehicleId, $timestamp, $allCharges) {
    $nearest = null;
    $minDiff = PHP_INT_MAX;

    foreach ($allCharges as $charge) {
        if ($charge['vehicleId'] == $vehicleId) {
            $diff = abs($charge['datetime'] - $timestamp);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $nearest = $charge;
            }
        }
    }

    return $nearest;
}

// Variabler til beregning af løbende gennemsnit
$runningKm = [];
$runningKwh = [];
$runningPris = [];

// Loop gennem vehicle_charges og beregn værdier
foreach ($vehicleCharges as $index => $charge) {
    $vehicleId = $charge['vehicleId'];
    $timestamp = $charge['cablePluggedInAt'];

    // Find nærmeste opladning
    $nearestCharge = findNearestCharge($vehicleId, $timestamp, $allCharges);
    
    if ($nearestCharge) {
        $kwh = $nearestCharge['kwh'];
        $pris = $nearestCharge['pris'];

        // Beregn km kørt siden sidste måling
        if ($index > 0 && $vehicleCharges[$index - 1]['vehicleId'] == $vehicleId) {
            $kmDriven = $charge['odometer'] - $vehicleCharges[$index - 1]['odometer'];
        } else {
            $kmDriven = 0;
        }

        // Opdater løbende summer
        if (!isset($runningKm[$vehicleId])) {
            $runningKm[$vehicleId] = 0;
            $runningKwh[$vehicleId] = 0;
            $runningPris[$vehicleId] = 0;
        }

        $runningKm[$vehicleId] += $kmDriven;
        $runningKwh[$vehicleId] += $kwh;
        $runningPris[$vehicleId] += $pris;

        // Beregn løbende gennemsnit
        $kmPerKwh = $runningKwh[$vehicleId] > 0 ? round($runningKm[$vehicleId] / $runningKwh[$vehicleId], 2) : null;
        $krPerKm = $runningKm[$vehicleId] > 0 ? round($runningPris[$vehicleId] / $runningKm[$vehicleId], 2) : null;

        // Udskriv resultater
        echo "Bil: $vehicleId | Dato: " . date('Y-m-d H:i', $timestamp) . " | KM kørt: $kmDriven | Løbende KM: {$runningKm[$vehicleId]} | KM/kWh: $kmPerKwh | Kr/KM: $krPerKm<br>";
    }
}
?>
