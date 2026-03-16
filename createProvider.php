<?php
require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$name = isset($data['name']) ? trim((string) $data['name']) : '';

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Navn må ikke være tomt']);
    exit;
}

try {
    $db  = DatabaseManager::getChargesDb();
    $id  = QueryBuilder::insertProvider($db, $name);
    echo json_encode(['success' => true, 'id' => $id, 'providerName' => $name]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('createProvider.php: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
