<?php
/** Create a charging provider. POST JSON: { name } */

$name = Http::str(Http::body(), 'name');
if ($name === '') {
    Http::fail('Navn må ikke være tomt');
}

$db = DatabaseManager::getChargesDb();
$id = ProviderRepository::insert($db, $name);
CacheHelper::invalidateProviderCaches();
Http::send(['success' => true, 'id' => $id, 'providerName' => $name]);
