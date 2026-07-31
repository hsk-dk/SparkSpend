<?php
$data = json_decode(file_get_contents('php://input'), true);
$id   = isset($data['id']) ? (int) $data['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ugyldigt id']);
    exit;
}

$db    = DatabaseManager::getChargesDb();
$count = QueryBuilder::providerUsageCount($db, $id);
if ($count > 0) {
    http_response_code(409);
    echo json_encode(['error' => "Udbyderen er tilknyttet {$count} ladning(er) og kan ikke slettes"]);
    exit;
}
QueryBuilder::deleteProvider($db, $id);
QueryBuilder::fileCacheInvalidatePattern('providerstats_');
echo json_encode(['success' => true]);
