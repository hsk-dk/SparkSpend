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

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['id']) || !isset($data['vehicleId']) || !isset($data['providerId']) ||
        !isset($data['datetime']) || !isset($data['kwh']) || !isset($data['pris'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required fields'
        ]);
        exit;
    }

    // Validate and format datetime
    try {
        $dateTime = new DateTime($data['datetime']);
        $dateTime->setTimezone(new DateTimeZone('UTC'));
        $dateTime = $dateTime->format('Y-m-d\TH:i:00\Z');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid date format'
        ]);
        exit;
    }

    $db = DatabaseManager::getChargesDb();
    $result = QueryBuilder::updateExternalCharge($db, [
        'id' => $data['id'],
        'vehicleId' => $data['vehicleId'],
        'providerId' => $data['providerId'],
        'kwh' => $data['kwh'],
        'cost' => $data['pris'],
        'chargeDate' => $dateTime,
    ]);

    if ($result) {
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

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in updateExtCharge.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in updateExtCharge.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

