<?php
/**
 * Get all vehicles
 * Returns a JSON array of all registered vehicles
 */

$db = DatabaseManager::getChargesDb();
$vehicles = QueryBuilder::selectAllVehicles($db);

http_response_code(200);
echo json_encode($vehicles);
