<?php
/**
 * Update internal charge vehicle assignment
 *
 * POST JSON body:
 * - id: Charge ID
 * - vehicleId: New Vehicle ID
 */

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$id        = intval($data['id']        ?? 0);
$vehicleId = intval($data['vehicleId'] ?? 0);

if (!$data || $id <= 0 || $vehicleId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: id, vehicleId'
    ]);
    exit;
}

$db = DatabaseManager::getChargesDb();
$result = QueryBuilder::updateInternalCharge($db, [
    'id'          => $id,
    'vehicleId'   => $vehicleId,
    'consumedKwh' => 0,  // Not updated in this endpoint
    'cost'        => 0   // Not updated in this endpoint
]);

if ($result) {
    foreach (['dashboard_', 'ev_compare_', 'annual_', 'analytics_', 'bill_', 'efficiency_', 'providerstats_', 'vehicle_compare_'] as $_p) {
        QueryBuilder::fileCacheInvalidatePattern($_p);
    }
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Internal charge updated'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error updating charge'
    ]);
}
