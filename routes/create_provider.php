<?php
$data = json_decode(file_get_contents('php://input'), true);
$name = isset($data['name']) ? trim((string) $data['name']) : '';

if ($name === '') {
    jsonError('Navn må ikke være tomt', 400);
}

$db  = DatabaseManager::getChargesDb();
$id  = QueryBuilder::insertProvider($db, $name);
QueryBuilder::invalidateProviderCaches();
echo json_encode(['success' => true, 'id' => $id, 'providerName' => $name]);
