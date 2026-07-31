<?php
$data = json_decode(file_get_contents('php://input'), true);
$name = isset($data['name']) ? trim((string) $data['name']) : '';

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Navn må ikke være tomt']);
    exit;
}

$db  = DatabaseManager::getChargesDb();
$id  = QueryBuilder::insertProvider($db, $name);
QueryBuilder::fileCacheInvalidatePattern('providerstats_');
echo json_encode(['success' => true, 'id' => $id, 'providerName' => $name]);
