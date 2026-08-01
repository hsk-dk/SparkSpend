<?php
/**
 * Receive and store vehicle telemetry data
 *
 * POST JSON body:
 * - vehicleId: Vehicle ID
 * - odometer: Current odometer reading (km)
 */

// API key authentication — required. If VEHICLE_API_KEY is not set in .env,
// the endpoint rejects all requests (fail closed).
$apiKey = Config::vehicleApiKey();
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Endpoint not configured — set VEHICLE_API_KEY in .env']);
    exit;
}
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($authHeader, 'Bearer ') || !hash_equals($apiKey, substr($authHeader, 7))) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="SparkSpend"');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
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
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing vehicleId or odometer']);
    exit;
}

$vehicleId = (int) $input['vehicleId'];
$odometer  = $input['odometer'];

if ($vehicleId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'vehicleId must be a positive integer']);
    exit;
}

if (!is_numeric($odometer) || (float)$odometer <= 0 || (float)$odometer >= 2000000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'odometer must be a number between 0 and 2,000,000']);
    exit;
}

// Generate current timestamp in UTC
$dt = new DateTime("now", new DateTimeZone("UTC"));
$cablePluggedInAt = $dt->format("Y-m-d\TH:i:s\Z");

// Store vehicle data
$db          = DatabaseManager::getChargesDb();
$vehicleName = isset($input['vehicleName']) ? trim((string) $input['vehicleName']) : '';

// Verify vehicleId exists in vehicles table
$existing = QueryBuilder::selectVehicleById($db, $vehicleId);
if ($existing === null && $vehicleName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Unknown vehicleId and no vehicleName provided to create it']);
    exit;
}

// Upsert vehicles table
QueryBuilder::upsertVehicle($db, $vehicleId, $vehicleName);

$result = QueryBuilder::insertVehicleData($db, [
    'vehicleId' => $vehicleId,
    'timestamp' => $cablePluggedInAt,
    'odometer'  => $input['odometer']
]);

if ($result) {
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Data stored']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error storing data']);
}
