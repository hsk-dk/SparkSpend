<?php
/**
 * Update external charge
 *
 * POST JSON body:
 * - id: Charge ID
 * - vehicleId: Vehicle ID
 * - providerId: Provider ID
 * - datetime: Date/time in ISO format
 * - kwh: kWh consumed
 * - pris: Cost
 */

$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Extract and type-cast parameters
$id         = intval($data['id']         ?? 0);
$vehicleId  = intval($data['vehicleId']  ?? 0);
$providerId = intval($data['providerId'] ?? 0);
$kwh        = floatval($data['kwh']      ?? 0);
$pris       = floatval($data['pris']     ?? 0);

if (!$data || $id <= 0 || $vehicleId <= 0 || !isset($data['datetime'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}
if (!is_numeric($data['kwh'] ?? '') || $kwh <= 0 || $kwh > 1000 || !is_finite($kwh)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'kwh skal være et tal mellem 0 og 1000']);
    exit;
}
if (!is_numeric($data['pris'] ?? '') || $pris < 0 || $pris > 50000 || !is_finite($pris)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'pris skal være et tal mellem 0 og 50000']);
    exit;
}

// Validate and format datetime
try {
    $dateTime = new DateTime($data['datetime']);
    $dateTime->setTimezone(new DateTimeZone('UTC'));
    $dateTime = $dateTime->format('Y-m-d\TH:i:00\Z');
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

$db = DatabaseManager::getChargesDb();
$result = QueryBuilder::updateExternalCharge($db, [
    'id'         => $id,
    'vehicleId'  => $vehicleId,
    'providerId' => $providerId,
    'kwh'        => $kwh,
    'cost'       => $pris,
    'chargeDate' => $dateTime,
]);

if ($result) {
    foreach (['dashboard_', 'ev_compare_', 'annual_', 'analytics_', 'bill_', 'efficiency_', 'providerstats_', 'vehicle_compare_'] as $_p) {
        QueryBuilder::fileCacheInvalidatePattern($_p);
    }
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'External charge updated'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error updating charge'
    ]);
}
