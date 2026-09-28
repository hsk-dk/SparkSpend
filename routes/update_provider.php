<?php
$data = json_decode(file_get_contents('php://input'), true);
$id   = isset($data['id'])   ? (int) $data['id']            : 0;
$name = isset($data['name']) ? trim((string) $data['name']) : '';

if ($id <= 0 || $name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ugyldigt id eller navn']);
    exit;
}

$db = DatabaseManager::getChargesDb();
QueryBuilder::updateProvider($db, $id, $name);
QueryBuilder::invalidateProviderCaches();
echo json_encode(['success' => true]);
