<?php
header('Content-Type: application/json');

// Tilføj error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

    // Hent parametre fra GET (samme som getCharges.php)
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $dateRange = isset($_GET['dateRange']) ? $_GET['dateRange'] : '';

try {
    // Forbind til SQLite-databasen
    $db = new SQLite3('data/charging_data.db');
    
    // DEBUG: Log input parametre
    error_log("DEBUG getEfficiencyStats: filter=$filter, dateRange=$dateRange");

// Parse dateRange hvis det er sat (støtter både dansk 'til ' og engelsk ' to ')
$startTimestamp = null;
$endTimestamp = null;
if ($dateRange) {
    // DEBUG: Log original dateRange
    error_log("DEBUG: Original dateRange: '$dateRange'");
    
    // Håndter både dansk 'til ' og engelsk ' to ' separatorer
    $dates = null;
    if (strpos($dateRange, ' til ') !== false) {
        $dates = explode(' til ', $dateRange);
    } elseif (strpos($dateRange, ' to ') !== false) {
        $dates = explode(' to ', $dateRange);
    } else {
        // Enkelt dato (ingen separator)
        $dates = [$dateRange];
    }
    
    if ($dates && count($dates) == 1) { 
        // Enkelt dato
        $startTimestamp = strtotime(trim($dates[0]) . " 00:00:00");
        $endTimestamp = strtotime(trim($dates[0]) . " 23:59:59");
    } elseif ($dates && count($dates) == 2) {
        // Dato interval
        $startTimestamp = strtotime(trim($dates[0]) . " 00:00:00");
        $endTimestamp = strtotime(trim($dates[1]) . " 23:59:59");
    }
    
    // DEBUG: Log parsed dates
    error_log("DEBUG: Parsed dates - start: " . ($startTimestamp ? date('Y-m-d H:i:s', $startTimestamp) : 'null') . 
              ", end: " . ($endTimestamp ? date('Y-m-d H:i:s', $endTimestamp) : 'null'));
}

// Byg WHERE clause for vehicle_charges
$whereConditions = [];
if ($dateRange) {
    // Håndter både dansk 'til ' og engelsk ' to ' separatorer
    $dates = null;
    if (strpos($dateRange, ' til ') !== false) {
        $dates = explode(' til ', $dateRange);
    } elseif (strpos($dateRange, ' to ') !== false) {
        $dates = explode(' to ', $dateRange);
    } else {
        // Enkelt dato (ingen separator)
        $dates = [$dateRange];
    }
    
    if ($dates && count($dates) == 1) { 
        $startDate = $db->escapeString(trim($dates[0]));
        $whereConditions[] = "date(cablePluggedInAt) = '$startDate'";
    } elseif ($dates && count($dates) == 2) {
        $startDate = $db->escapeString(trim($dates[0]));
        $endDate = $db->escapeString(trim($dates[1]));
        $whereConditions[] = "date(cablePluggedInAt) BETWEEN '$startDate' AND '$endDate'";
    }
}
if ($filter !== 'all') {
    $whereConditions[] = "vehicleId = " . intval($filter);
}

// DEBUG: Log timestamps
error_log("DEBUG: dateRange=$dateRange");

// Hent vehicle_charges inden for den valgte periode og bil
$query = "SELECT vehicleId, cablePluggedInAt, odometer, chargeType FROM vehicle_charges";
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(" AND ", $whereConditions);
}
$query .= " ORDER BY vehicleId, cablePluggedInAt";

// DEBUG: Log query
error_log("DEBUG vehicle_charges query: $query");

$vehicleChargesResult = $db->query($query);

if (!$vehicleChargesResult) {
    throw new Exception("SQL fejl i vehicle_charges query: " . $db->lastErrorMsg());
}

// Byg WHERE clause for charges og ext_charges (samme som getCharges.php)
$whereConditions2 = [];
$whereConditions3 = [];
if ($dateRange) {
    // Håndter både dansk 'til ' og engelsk ' to ' separatorer
    $dates = null;
    if (strpos($dateRange, ' til ') !== false) {
        $dates = explode(' til ', $dateRange);
    } elseif (strpos($dateRange, ' to ') !== false) {
        $dates = explode(' to ', $dateRange);
    } else {
        // Enkelt dato (ingen separator)
        $dates = [$dateRange];
    }
    
    if ($dates && count($dates) == 1) { 
        $startDate = $db->escapeString(trim($dates[0]));
        $whereConditions2[] = "date(createdAt) = '$startDate'";
        $whereConditions3[] = "date(datetime) = '$startDate'";
    } elseif ($dates && count($dates) == 2) {
        $startDate = $db->escapeString(trim($dates[0]));
        $endDate = $db->escapeString(trim($dates[1]));
        $whereConditions2[] = "date(createdAt) BETWEEN '$startDate' AND '$endDate'";
        $whereConditions3[] = "date(datetime) BETWEEN '$startDate' AND '$endDate'";
    }
}
if ($filter !== 'all') {
    $whereConditions2[] = "vehicleId = " . intval($filter);
    $whereConditions3[] = "vehicleId = " . intval($filter);
}

// Hent charges (interne ladninger)
$query2 = "SELECT vehicleId, createdAt as datetime, consumedKwh as kwh, cost as pris FROM charges";
if (!empty($whereConditions2)) {
    $query2 .= " WHERE " . implode(" AND ", $whereConditions2);
}

// DEBUG: Log charges query
error_log("DEBUG charges query: $query2");

// Hent ext_charges (eksterne ladninger)  
$query3 = "SELECT vehicleId, datetime, kwh, pris FROM ext_charges";
if (!empty($whereConditions3)) {
    $query3 .= " WHERE " . implode(" AND ", $whereConditions3);
}

// DEBUG: Log ext_charges query
error_log("DEBUG ext_charges query: $query3");

// Først hent data fra charges tabellen
$chargesResult = $db->query($query2);
if (!$chargesResult) {
    throw new Exception("SQL fejl i charges query: " . $db->lastErrorMsg());
}

// Dernæst hent data fra ext_charges tabellen
$extChargesResult = $db->query($query3);
if (!$extChargesResult) {
    throw new Exception("SQL fejl i ext_charges query: " . $db->lastErrorMsg());
}

// Konverter resultater til arrays
$vehicleCharges = [];
while ($row = $vehicleChargesResult->fetchArray(SQLITE3_ASSOC)) {
    $row['cablePluggedInAt'] = strtotime($row['cablePluggedInAt']);
    $vehicleCharges[] = $row;
}

// DEBUG: Log antal rækker
error_log("DEBUG: Antal vehicle_charges fundet: " . count($vehicleCharges));

$allCharges = [];

// Hent data fra charges tabellen
$chargesCount = 0;
while ($row = $chargesResult->fetchArray(SQLITE3_ASSOC)) {
    $row['datetime'] = strtotime($row['datetime']);
    $allCharges[] = $row;
    $chargesCount++;
}

// Hent data fra ext_charges tabellen
$extChargesCount = 0;
while ($row = $extChargesResult->fetchArray(SQLITE3_ASSOC)) {
    $row['datetime'] = strtotime($row['datetime']);
    $allCharges[] = $row;
    $extChargesCount++;
}

// DEBUG: Log antal rækker fra hver tabel separat
error_log("DEBUG: Charges fundet: $chargesCount, Ext_charges fundet: $extChargesCount, Total: " . count($allCharges));

// Sortér efter timestamp
usort($allCharges, function($a, $b) {
    return $a['datetime'] - $b['datetime'];
});

$db->close();

// Hjælpefunktion til at finde nærmeste opladning i all_charges
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

// Variabler til løbende summer per bil
$results = [];
$runningKm = [];
$runningKwh = [];
$runningPris = [];

foreach ($vehicleCharges as $index => $charge) {
    $vehicleId = $charge['vehicleId'];
    $timestamp = $charge['cablePluggedInAt'];

    // Beregn km kørt siden sidste måling for samme bil
    // Bemærk: odometer er km-stand når ladningen STARTER
    // Så kmDriven er km kørt mellem forrige og denne ladning
    if ($index > 0 && $vehicleCharges[$index - 1]['vehicleId'] == $vehicleId) {
        $kmDriven = $charge['odometer'] - $vehicleCharges[$index - 1]['odometer'];
        
        // Find opladningen der gav energi til denne tur
        // Det er den FORRIGE opladning, ikke den nærmeste til denne ladning
        $previousCharge = findNearestCharge($vehicleId, $vehicleCharges[$index - 1]['cablePluggedInAt'], $allCharges);
        
        if ($previousCharge && $kmDriven > 0) {
            $kwh = $previousCharge['kwh'];
            $pris = $previousCharge['pris'];
            
            // DEBUG: Log beregningsdata for de første par entries
            if ($index < 5) {
                error_log("DEBUG entry $index: vehicleId=$vehicleId, kmDriven=$kmDriven, kwh=$kwh (from previous charge)");
            }
            
            // Initialiser summer for denne bil, hvis ikke sat
            if (!isset($runningKm[$vehicleId])) {
                $runningKm[$vehicleId]   = 0;
                $runningKwh[$vehicleId]  = 0;
                $runningPris[$vehicleId] = 0;
            }

            // Beregn efficiency baseret på km kørt og kWh fra forrige opladning
            $kmPerKwh = round($kmDriven / $kwh, 2);
            $krPerKm = round($pris / $kmDriven, 2);
            
            // Opdater løbende summer for samlet efficiency
            $runningKm[$vehicleId]   += $kmDriven;
            $runningKwh[$vehicleId]  += $kwh;
            $runningPris[$vehicleId] += $pris;

            // Beregn samlet gennemsnit (løbende total)
            $totalKmPerKwh = $runningKwh[$vehicleId] > 0 ? round($runningKm[$vehicleId] / $runningKwh[$vehicleId], 2) : null;
            $totalKrPerKm  = $runningKm[$vehicleId] > 0 ? round($runningPris[$vehicleId] / $runningKm[$vehicleId], 2) : null;

            // Gem resultaterne med både individuel og samlet efficiency
            $results[] = [
                'vehicleId'      => $vehicleId,
                'timestamp'      => $timestamp,
                'date'           => date('Y-m-d H:i', $timestamp),
                'kmDriven'       => $kmDriven,
                'totalKm'        => $runningKm[$vehicleId],
                'kwh'            => $kwh,
                'kmPerKwh'       => $kmPerKwh,           // Efficiency for denne tur
                'krPerKm'        => $krPerKm,            // Kr per km for denne tur
                'totalKmPerKwh'  => $totalKmPerKwh,      // Løbende gennemsnit
                'totalKrPerKm'   => $totalKrPerKm        // Løbende gennemsnit
            ];
        }
    } else {
        // Første måling for denne bil - vi har ingen tidligere data at sammenligne med
        // DEBUG: Log første entry
        if ($index < 3) {
            error_log("DEBUG entry $index: vehicleId=$vehicleId, første måling - springer over");
        }
    }
}

    // DEBUG: Log final results
    error_log("DEBUG: Final results count: " . count($results));
    if (count($results) > 0) {
        error_log("DEBUG: First result: " . json_encode($results[0]));
    }

    echo json_encode($results);
    
} catch (Exception $e) {
    // Returnér fejl som JSON
    echo json_encode(['error' => $e->getMessage()]);
}
?>
