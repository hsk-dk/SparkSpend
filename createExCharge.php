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

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
    // Validate required fields
    $vehicleId = isset($_POST['vehicleId']) ? $_POST['vehicleId'] : '';
    $providerId = isset($_POST['providerId']) ? $_POST['providerId'] : '';
    $chargeDateTime = isset($_POST['chargeDateTime']) ? $_POST['chargeDateTime'] : '';
    $kwh = isset($_POST['kwh']) ? $_POST['kwh'] : '';
    $pris = isset($_POST['pris']) ? $_POST['pris'] : '';

    if (!$vehicleId || !$providerId || !$chargeDateTime || !$kwh || !$pris) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required fields: vehicleId, providerId, chargeDateTime, kwh, pris'
        ]);
        exit;
    }

    // Validate and format date
    try {
        $chargeDate = new DateTime($chargeDateTime);
        $chargeDate = $chargeDate->format('Y-m-d\TH:i:00\Z');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid charge date format'
        ]);
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

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in createExCharge.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in createExCharge.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

