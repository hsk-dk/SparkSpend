<?php
/**
 * Update the vehicle of an internal (Monta) charge; marks it as manually paired.
 * POST JSON: { id, vehicleId }
 */

$data      = Http::body();
$id        = Http::int($data, 'id');
$vehicleId = Http::int($data, 'vehicleId');
if ($id <= 0 || $vehicleId <= 0) {
    Http::fail('Missing required fields: id, vehicleId');
}

$db = DatabaseManager::getChargesDb();
if (!Db::rowExists($db, 'vehicles', $vehicleId)) {
    Http::fail('Ukendt vehicleId', 422);
}
if (!Db::rowExists($db, 'charges', $id)) {
    Http::fail('Ladning ikke fundet', 404);
}

$stmt = $db->prepare("UPDATE charges SET vehicleId = ?, pairingSource = 'manual' WHERE id = ?");
if (!$stmt->execute([$vehicleId, $id])) {
    Http::fail('Error updating charge', 500);
}
CacheHelper::invalidate(['charges']);
Http::send(['success' => true, 'message' => 'Internal charge updated']);
