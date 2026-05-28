<?php
/**
 * Receive and store vehicle telemetry data
 *
 * POST JSON body:
 * - vehicleId: Vehicle ID
 * - odometer: Current odometer reading (km)
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            "status" => "error",
            "message" => "Method not allowed"
        ]);
        exit;
    }

    // Read and parse JSON input
    $raw_input = file_get_contents('php://input');
    if ($raw_input === false) {
        http_response_code(400);
        error_log("Error reading input data in receive_vehicle_data.php");
        echo json_encode([
            "status" => "error",
            "message" => "Error reading input data"
        ]);
        exit;
    }

    $input = json_decode($raw_input, true);
    if ($input === null) {
        http_response_code(400);
        error_log("JSON decode error in receive_vehicle_data.php: " . json_last_error_msg());
        echo json_encode([
            "status" => "error",
            "message" => "Invalid JSON format"
        ]);
        exit;
    }

    // Validate required fields
    if (!isset($input['vehicleId']) || !isset($input['odometer'])) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "Missing vehicleId or odometer"
        ]);
        exit;
    }

    // Generate current timestamp in UTC (Z-suffix is reliably parsed by SQLite strftime)
    $dt = new DateTime("now", new DateTimeZone("UTC"));
    $cablePluggedInAt = $dt->format("Y-m-d\TH:i:s\Z");

    // Store vehicle data
    $db         = DatabaseManager::getChargesDb();
    $vehicleId  = (int) $input['vehicleId'];
    $vehicleName = isset($input['vehicleName']) ? trim((string) $input['vehicleName']) : '';

    // Upsert vehicles table so vehicleName stays current whenever the sender provides it.
    QueryBuilder::upsertVehicle($db, $vehicleId, $vehicleName);

    $result = QueryBuilder::insertVehicleData($db, [
        'vehicleId' => $vehicleId,
        'timestamp' => $cablePluggedInAt,
        'odometer'  => $input['odometer']
    ]);

    if ($result) {
        http_response_code(201);
        echo json_encode([
            "status" => "success",
            "message" => "Data stored"
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Error storing data"
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in receive_vehicle_data.php: " . $e->getMessage());
    echo json_encode([
        "status" => "error",
        "message" => "Database error occurred"
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    error_log("Error in receive_vehicle_data.php: " . $e->getMessage());
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>

