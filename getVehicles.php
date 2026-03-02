<?php
/**
 * Get all vehicles
 * Returns a JSON array of all registered vehicles
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
    $db = DatabaseManager::getChargesDb();
    $vehicles = QueryBuilder::selectAllVehicles($db);

    http_response_code(200);
    echo json_encode($vehicles);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in getVehicles.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in getVehicles.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>

