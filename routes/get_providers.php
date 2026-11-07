<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Get all charging providers
 * Returns a JSON array of all registered providers
 */

$db = DatabaseManager::getChargesDb();
$providers = ProviderRepository::getAll($db);

http_response_code(200);
echo json_encode($providers);
