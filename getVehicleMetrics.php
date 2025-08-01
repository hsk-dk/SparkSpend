<?php
require 'includes/configuration.php';
// Forbind til SQLite-database
$db = new SQLite3($dbPath);

// Hent GET-parametre for at filtrere efter dato (hvis nødvendigt)
$startDate = isset($_GET['startDate']) ? strtotime($_GET['startDate']) : 0;
$endDate   = isset($_GET['endDate']) ? strtotime($_GET['endDate']) : PHP_INT_MAX;

// Hent vehicle_charges – evt. filtreret efter dato
$query = "SELECT vehicleId, cablePluggedInAt, odometer, chargeType 
          FROM vehicle_charges 
          WHERE strftime('%s', cablePluggedInAt) BETWEEN '$startDate' AND '$endDate'
          ORDER BY vehicleId, cablePluggedInAt";
$vehicleChargesResult = $db->query($query);

// Hent all_charges – evt. filtreret efter dato
$query = "SELECT vehicleId, datetime, kwh, pris 
          FROM all_charges 
          WHERE strftime('%s', datetime) BETWEEN '$startDate' AND '$endDate'
          ORDER BY vehicleId, datetime";
$allChargesResult = $db->query($query);

// Konverter resultater til arrays
$vehicleCharges = [];
while ($row = $vehicleChargesResult->fetchArray(SQLITE3_ASSOC)) {
    $row['cablePluggedInAt'] = strtotime($row['cablePluggedInAt']); // Konverter til timestamp
    $vehicleCharges[] = $row;
}

$allCharges = [];
while ($row = $allChargesResult->fetchArray(SQLITE3_ASSOC)) {
    $row['datetime'] = strtotime($row['datetime']); // Konverter til timestamp
    $allCharges[] = $row;
}

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

// Initialiser arrays til løbende summer for hver bil
$runningKm = [];
$runningKwh = [];
$runningPris = [];

// Array til at samle data, der skal returneres pr. "målepunkter" (f.eks. per opladning)
$metrics = [];

foreach ($vehicleCharges as $index => $charge) {
    $vehicleId = $charge['vehicleId'];
    $timestamp = $charge['cablePluggedInAt'];

    // Find nærmeste opladning
    $nearestCharge = findNearestCharge($vehicleId, $timestamp, $allCharges);
    
    if ($nearestCharge) {
        $kwh = $nearestCharge['kwh'];
        $pris = $nearestCharge['pris'];

        // Beregn km kørt siden sidste måling for samme bil
        if ($index > 0 && $vehicleCharges[$index - 1]['vehicleId'] == $vehicleId) {
            $kmDriven = $charge['odometer'] - $vehicleCharges[$index - 1]['odometer'];
        } else {
            $kmDriven = 0;
        }

        // Initier summer for bilen, hvis det er første gang
        if (!isset($runningKm[$vehicleId])) {
            $runningKm[$vehicleId] = 0;
            $runningKwh[$vehicleId] = 0;
            $runningPris[$vehicleId] = 0;
        }

        // Opdater summerne
        $runningKm[$vehicleId] += $kmDriven;
        $runningKwh[$vehicleId] += $kwh;
        $runningPris[$vehicleId] += $pris;

        // Beregn gennemsnit
        $kmPerKwh = $runningKwh[$vehicleId] > 0 ? round($runningKm[$vehicleId] / $runningKwh[$vehicleId], 2) : null;
        $krPerKm = $runningKm[$vehicleId] > 0 ? round($runningPris[$vehicleId] / $runningKm[$vehicleId], 2) : null;

        // Saml data – du kan evt. også inkludere timestamp for at plotte udviklingen over tid
        $metrics[] = [
            'vehicleId'   => $vehicleId,
            'timestamp'   => $timestamp,
            'kmDriven'    => $kmDriven,
            'totalKm'     => $runningKm[$vehicleId],
            'kmPerKwh'    => $kmPerKwh,
            'krPerKm'     => $krPerKm,
            'date'        => date('Y-m-d H:i', $timestamp)
        ];
    }
}

// Returner data i JSON-format
header('Content-Type: application/json');
echo json_encode($metrics);
