<?php
require 'includes/configuration.php';
date_default_timezone_set('Europe/Copenhagen');

$db = new SQLite3($dbPath);

// Læs JSON-indhold fra request body
$raw_input = file_get_contents('php://input');
if ($raw_input === false) {
    error_log("Fejl ved læsning af input-data", 3, "/var/log/php_errors.log");
    echo json_encode(["status" => "error", "message" => "Fejl ved læsning af input-data"]);
    exit;
}
$input = json_decode($raw_input, true);
if ($input === null) {
    error_log("Fejl ved JSON-dekodning: " . json_last_error_msg(), 3, "/var/log/php_errors.log");
    echo json_encode(["status" => "error", "message" => "Ugyldigt JSON-format"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($input['vehicleId']) || !isset($input['odometer'])) {
        echo json_encode(["status" => "error", "message" => "Manglende data"]);
        exit;
    }

    // Brug den aktuelle tid som kabeltilslutningstidspunkt (UTC)
	$dt = new DateTime("now", new DateTimeZone("Europe/Copenhagen"));
	$cablePluggedInAt = $dt->format("Y-m-d\TH:i:sP"); // Inkluderer tidszoneoffset


    // Forbered SQL-forespørgslen
    $stmt = $db->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (:vehicleId, :cablePluggedInAt, :odometer)");
    
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Databasefejl: Kunne ikke forberede forespørgsel"]);
        exit;
    }

    $stmt->bindValue(':vehicleId', $input['vehicleId'], SQLITE3_INTEGER);
    $stmt->bindValue(':cablePluggedInAt', $cablePluggedInAt, SQLITE3_TEXT);
    $stmt->bindValue(':odometer', $input['odometer'], SQLITE3_INTEGER);

    // Udfør forespørgslen
    $result = $stmt->execute();

    if ($result) {
        echo json_encode(["status" => "success", "message" => "Data gemt"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Fejl ved databaseindsættelse"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Ugyldig metode"]);
}


?>
