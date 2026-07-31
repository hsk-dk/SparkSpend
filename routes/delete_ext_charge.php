<?php
/**
 * Delete external charge
 *
 * POST JSON body:
 * - id: Charge ID to delete
 */

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing id parameter'
    ]);
    exit;
}

$db = DatabaseManager::getChargesDb();
$result = QueryBuilder::deleteExternalCharge($db, intval($data['id']));

if ($result) {
    foreach (['dashboard_', 'ev_compare_', 'annual_', 'analytics_', 'bill_', 'efficiency_', 'providerstats_', 'vehicle_compare_'] as $_p) {
        QueryBuilder::fileCacheInvalidatePattern($_p);
    }
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error deleting charge'
    ]);
}
