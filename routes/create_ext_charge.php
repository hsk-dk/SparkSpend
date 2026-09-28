<?php
/**
 * Create a new external charge record
 *
 * POST parameters:
 * - vehicleId: Vehicle ID
 * - providerId: Provider ID
 * - chargeDateTime: Charge date/time in ISO format
 * - kwh: kWh consumed
 * - pris: Cost in currency units
 */

// Accept JSON body or form POST
$ct    = $_SERVER['CONTENT_TYPE'] ?? '';
$input = str_contains($ct, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : $_POST;

// Extract and type-cast parameters
$vehicleId      = intval($input['vehicleId']    ?? 0);
$providerId     = intval($input['providerId']   ?? 0);
$chargeDateTime = trim($input['chargeDateTime'] ?? '');
$kwh            = floatval($input['kwh']        ?? 0);
$pris           = floatval($input['pris']       ?? 0);

// Presence check
if ($vehicleId <= 0 || $providerId <= 0 || $chargeDateTime === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: vehicleId, providerId, chargeDateTime']);
    exit;
}
// Numeric bounds
if (!is_numeric($input['kwh'] ?? '') || $kwh <= 0 || $kwh > 1000 || !is_finite($kwh)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'kwh skal være et tal mellem 0 og 1000']);
    exit;
}
if (!is_numeric($input['pris'] ?? '') || $pris < 0 || $pris > 50000 || !is_finite($pris)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'pris skal være et tal mellem 0 og 50000']);
    exit;
}

// Validate and format date
try {
    $chargeDate = new DateTime($chargeDateTime);
    $chargeDate->setTimezone(new DateTimeZone('UTC'));
    $chargeDate = $chargeDate->format('Y-m-d\TH:i:00\Z');
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid charge date format']);
    exit;
}

// Create charge record
$db = DatabaseManager::getChargesDb();
$result = QueryBuilder::insertExternalCharge($db, [
    'vehicleId' => $vehicleId,
    'providerId' => $providerId,
    'kwh' => $kwh,
    'cost' => $pris,
    'chargeDate' => $chargeDate,
    'provider' => '',
    'category' => ''
]);

if ($result['success']) {
    QueryBuilder::invalidateChargeCaches();
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'id' => $result['id']
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Error creating charge'
    ]);
}
