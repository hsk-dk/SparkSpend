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
    // Extract and type-cast parameters
    $vehicleId      = intval($_POST['vehicleId']    ?? 0);
    $providerId     = intval($_POST['providerId']   ?? 0);
    $chargeDateTime = trim($_POST['chargeDateTime'] ?? '');
    $kwh            = floatval($_POST['kwh']        ?? 0);
    $pris           = floatval($_POST['pris']       ?? 0);

    // Presence check
    if ($vehicleId <= 0 || $providerId <= 0 || $chargeDateTime === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: vehicleId, providerId, chargeDateTime']);
        exit;
    }
    // Numeric bounds
    if (!is_numeric($_POST['kwh'] ?? '') || $kwh <= 0 || $kwh > 1000) {
        http_response_code(400);
        echo json_encode(['error' => 'kwh skal være et tal mellem 0 og 1000']);
        exit;
    }
    if (!is_numeric($_POST['pris'] ?? '') || $pris <= 0 || $pris > 50000) {
        http_response_code(400);
        echo json_encode(['error' => 'pris skal være et tal mellem 0 og 50000']);
        exit;
    }

    // Validate and format date
    try {
        $chargeDate = new DateTime($chargeDateTime);
        $chargeDate->setTimezone(new DateTimeZone('UTC'));
        $chargeDate = $chargeDate->format('Y-m-d\TH:i:00\Z');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid charge date format']);
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

