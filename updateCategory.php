<?php
/**
 * Update charge category (vehicle assignment)
 *
 * POST JSON body:
 * - id: Charge ID
 * - vehicleId: New Vehicle ID
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input || !isset($input['id']) || !isset($input['vehicleId'])) {
        http_response_code(400);
        echo json_encode([
            "error" => "Missing id or vehicleId"
        ]);
        exit;
    }

    $db = DatabaseManager::getChargesDb();
    $result = QueryBuilder::updateChargeCategory($db, intval($input['id']), intval($input['vehicleId']));

    if ($result) {
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Charge updated"
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "error" => "Error updating charge"
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in updateCategory.php: " . $e->getMessage());
    echo json_encode([
        "error" => "Database error occurred"
    ]);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in updateCategory.php: " . $e->getMessage());
    echo json_encode([
        "error" => $e->getMessage()
    ]);
}
?>

