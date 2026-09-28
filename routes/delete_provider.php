<?php
$data = json_decode(file_get_contents('php://input'), true);
$id   = isset($data['id']) ? (int) $data['id'] : 0;

if ($id <= 0) {
    jsonError('Ugyldigt id', 400);
}

$db    = DatabaseManager::getChargesDb();
$count = QueryBuilder::providerUsageCount($db, $id);
if ($count > 0) {
    jsonError("Udbyderen er tilknyttet {$count} ladning(er) og kan ikke slettes", 409);
}
QueryBuilder::deleteProvider($db, $id);
QueryBuilder::invalidateProviderCaches();
echo json_encode(['success' => true]);
