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

// Update vehicleId and mark as manually paired
$stmt = $db->prepare("UPDATE charges SET vehicleId = ?, pairingSource = 'manual' WHERE id = ?");
$result = $stmt->execute([$vehicleId, $id]);

if ($result) {
    QueryBuilder::invalidateChargeCaches();
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
