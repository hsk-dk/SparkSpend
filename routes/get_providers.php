<?php
/**
 * Get all charging providers
 * Returns a JSON array of all registered providers
 */

$db = DatabaseManager::getChargesDb();
$providers = QueryBuilder::selectAllProviders($db);

http_response_code(200);
echo json_encode($providers);
