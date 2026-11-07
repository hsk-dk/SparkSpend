<?php
/** Rename a provider. POST JSON: { id, name } */

$data = Http::body();
$id   = Http::int($data, 'id');
$name = Http::str($data, 'name');
if ($id <= 0 || $name === '') {
    Http::fail('Ugyldigt id eller navn');
}

$db = DatabaseManager::getChargesDb();
ProviderRepository::update($db, $id, $name);
CacheHelper::invalidate(['providers']);
Http::send(['success' => true]);
