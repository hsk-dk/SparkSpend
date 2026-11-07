<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Receive and store vehicle telemetry data
 *
 * POST JSON body:
 * - vehicleId: Vehicle ID
 * - odometer: Current odometer reading (km)
 */

require_once __DIR__ . '/../includes/ApiAuth.php';

// API key authentication — required (fails closed: no key configured = 503).
ApiAuth::requireBearer(Config::vehicleApiKey());

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::fail('Method not allowed', 405);
}

// Read and parse JSON input
$raw_input = file_get_contents('php://input');
if ($raw_input === false) {
    http_response_code(400);
    error_log('Error reading input data in receive_vehicle_data.php');
    echo json_encode(['success' => false, 'error' => 'Error reading input data']);
    exit;
}

$input = json_decode($raw_input, true);
if ($input === null) {
    http_response_code(400);
    error_log('JSON decode error in receive_vehicle_data.php: ' . json_last_error_msg());
    echo json_encode(['success' => false, 'error' => 'Invalid JSON format']);
    exit;
}

// Validate required fields
if (!isset($input['vehicleId']) || !isset($input['odometer'])) {
    Http::fail('Missing vehicleId or odometer');
}

$vehicleId = (int) $input['vehicleId'];
$odometer  = $input['odometer'];

if ($vehicleId <= 0) {
    Http::fail('vehicleId must be a positive integer');
}

if (!is_numeric($odometer) || (float)$odometer <= 0 || (float)$odometer >= 2000000) {
    Http::fail('odometer must be a number between 0 and 2,000,000');
}

// Generate current timestamp in UTC
$dt = new DateTime("now", new DateTimeZone("UTC"));
$cablePluggedInAt = $dt->format("Y-m-d\TH:i:s\Z");

// Store vehicle data
$db          = DatabaseManager::getChargesDb();
$vehicleName = isset($input['vehicleName']) ? trim((string) $input['vehicleName']) : '';

// Verify vehicleId exists in vehicles table
$existing = VehicleRepository::getById($db, $vehicleId);
if ($existing === null && $vehicleName === '') {
    Http::fail('Unknown vehicleId and no vehicleName provided to create it', 422);
}

// Upsert vehicles table
VehicleRepository::upsert($db, $vehicleId, $vehicleName);

$result = VehicleRepository::insertOdometerReading($db, [
    'vehicleId' => $vehicleId,
    'timestamp' => $cablePluggedInAt,
    'odometer'  => $input['odometer']
]);

if ($result) {
    CacheHelper::invalidate(['ha', 'vehicles']);   // efficiency etc. read the odometer / plug-in readings
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Data stored']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error storing data']);
}
