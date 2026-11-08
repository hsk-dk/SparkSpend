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

return static function (Request $request): Response {
    // API key authentication — required (fails closed: no key configured = 503).
    ApiAuth::requireBearer(Config::vehicleApiKey(), 'VEHICLE_API_KEY', $request);

    // Validate request method
    if ($request->method !== 'POST') {
        Http::fail('Method not allowed', 405);
    }

    // Read and parse JSON input
    $input = json_decode($request->raw(), true);
    if ($input === null) {
        error_log('JSON decode error in receive_vehicle_data.php: ' . json_last_error_msg());
        Http::fail('Invalid JSON format');
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

    if (!$result) {
        Http::fail('Error storing data', 500);
    }
    CacheHelper::invalidate(['ha', 'vehicles']);   // efficiency etc. read the odometer / plug-in readings
    return Response::json(['success' => true, 'message' => 'Data stored'], 201);
};
