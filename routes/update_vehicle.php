<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/** Rename a vehicle. POST JSON: { id, name } */

$data = Http::body();
$id   = Http::int($data, 'id');
$name = Http::str($data, 'name');
if ($id <= 0 || $name === '') {
    Http::fail('Ugyldigt id eller navn');
}

$db = DatabaseManager::getChargesDb();
if (!Db::rowExists($db, 'vehicles', $id)) {
    Http::fail('Køretøj ikke fundet', 404);
}
VehicleRepository::update($db, $id, $name);
CacheHelper::invalidate(['vehicles']);
Http::send(['success' => true]);
