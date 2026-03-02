<?php
/**
 * Get all charging providers
 * Returns a JSON array of all registered providers
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
    $db = DatabaseManager::getChargesDb();
    $providers = QueryBuilder::selectAllProviders($db);

    http_response_code(200);
    echo json_encode($providers);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in getProvideres.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in getProvideres.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>

