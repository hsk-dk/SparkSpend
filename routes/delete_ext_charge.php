<?php
/** Delete an external charge. POST JSON: { id } */

$data = Http::body();
if (!isset($data['id'])) {
    Http::fail('Missing id parameter');
}
$id = Http::int($data, 'id');

$db = DatabaseManager::getChargesDb();
if (!QueryBuilder::rowExists($db, 'ext_charges', $id)) {
    Http::fail('Ladning ikke fundet', 404);
}
if (!QueryBuilder::deleteExternalCharge($db, $id)) {
    Http::fail('Error deleting charge', 500);
}
QueryBuilder::invalidateChargeCaches();
Http::send(['success' => true]);
