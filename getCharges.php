<?php
require 'includes/configuration.php';
header('Content-Type: application/json');

try {
    $db = new SQLite3($dbPath);

    // Hent filterparametre
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $showZeroKwh = (isset($_GET['showZeroKwh']) && $_GET['showZeroKwh'] === 'true') ? true : false;
    $dateRange = isset($_GET['dateRange']) ? $_GET['dateRange'] : '';

    // Byg filtre for hver tabel
    $where1 = " WHERE 1=1 "; // For interne ladninger (charges)
    $where2 = " WHERE 1=1 "; // For eksterne ladninger (ext_charges)

    if ($filter !== 'all') {
        $where1 .= " AND vehicleId = " . intval($filter);
        $where2 .= " AND vehicleId = " . intval($filter);
    }
    if (!$showZeroKwh) {
        $where1 .= " AND consumedKwh > 0";
        $where2 .= " AND kwh > 0";
    }
   if ($dateRange) {
    // Håndter både dansk 'til ' og engelsk ' to ' separatorer
    $dates = null;
    if (strpos($dateRange, 'til ') !== false) {
        $dates = explode('til ', $dateRange);
    } elseif (strpos($dateRange, ' to ') !== false) {
        $dates = explode(' to ', $dateRange);
    }
    
    if ($dates && count($dates) == 1) { 
        $startDate = $db->escapeString(trim($dates[0]));
        $where1 .= " AND date(createdAt) = '$startDate'";
        $where2 .= " AND date(datetime) = '$startDate'";
    } elseif ($dates && count($dates) == 2) {
        $startDate = $db->escapeString(trim($dates[0]));
        $endDate   = $db->escapeString(trim($dates[1]));
        $where1 .= " AND date(createdAt) BETWEEN '$startDate' AND '$endDate'";
        $where2 .= " AND date(datetime) BETWEEN '$startDate' AND '$endDate'";
    }
}


    // Forespørgsel for interne ladninger (fra charges)
	$query1 = "SELECT id, strftime('%Y-%m-%dT%H:%M:%S', createdAt) AS datetime, consumedKwh AS kwh, cost AS pris, vehicleId, NULL AS providerId, 'internal' AS source FROM charges" . $where1;

	// Forespørgsel for eksterne ladninger (fra ext_charges)
	$query2 = "SELECT id, strftime('%Y-%m-%dT%H:%M:%S', datetime) AS datetime, kwh, pris, vehicleId, providerId, 'external' AS source FROM ext_charges" . $where2;

	// Kombiner med UNION ALL og sorter efter datetime (nyeste først)
	$query = "$query1 UNION ALL $query2 ORDER BY datetime ASC";


    // Prøv at forberede og udføre forespørgslen
    $result = $db->query($query);

    if (!$result) {
        throw new Exception("Fejl i SQL forespørgslen: " . $db->lastErrorMsg());
    }

    $charges = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $charges[] = $row;
    }

    // Kun returnere JSON-data uden yderligere output
    echo json_encode($charges);
} catch (Exception $e) {
    // Hvis en fejl opstår, returneres JSON-fejlmeddelelsen
    echo json_encode(['error' => $e->getMessage()]);
}
?>
