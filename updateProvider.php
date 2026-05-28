<?php
require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id   = isset($data['id'])   ? (int) $data['id']            : 0;
$name = isset($data['name']) ? trim((string) $data['name']) : '';

if ($id <= 0 || $name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Ugyldigt id eller navn']);
    exit;
}

try {
    $db = DatabaseManager::getChargesDb();
    QueryBuilder::updateProvider($db, $id, $name);
    QueryBuilder::fileCacheInvalidatePattern('providerstats_');
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('updateProvider.php: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
