<?php
/**
 * Update an external charge.
 * POST JSON: id, vehicleId, providerId, datetime (ISO), kwh, pris
 */
require_once __DIR__ . '/../includes/ChargeInput.php';

$data       = Http::body();
$id         = Http::int($data, 'id');
$vehicleId  = Http::int($data, 'vehicleId');
$providerId = Http::int($data, 'providerId');

if ($id <= 0 || $vehicleId <= 0 || !isset($data['datetime'])) {
    Http::fail('Missing required fields');
}
$v = ChargeInput::validate($data, 'datetime', 'Invalid date format');

$db = DatabaseManager::getChargesDb();
if (!QueryBuilder::rowExists($db, 'vehicles', $vehicleId)) {
    Http::fail('Ukendt vehicleId', 422);
}
if ($providerId > 0 && !QueryBuilder::rowExists($db, 'providers', $providerId)) {
    Http::fail('Ukendt providerId', 422);
}
if (!QueryBuilder::rowExists($db, 'ext_charges', $id)) {
    Http::fail('Ladning ikke fundet', 404);
}

$ok = QueryBuilder::updateExternalCharge($db, [
    'id'         => $id,
    'vehicleId'  => $vehicleId,
    'providerId' => $providerId,
    'kwh'        => $v['kwh'],
    'cost'       => $v['pris'],
    'chargeDate' => $v['date'],
]);
if (!$ok) {
    Http::fail('Error updating charge', 500);
}
QueryBuilder::invalidateChargeCaches();
Http::send(['success' => true, 'message' => 'External charge updated']);
