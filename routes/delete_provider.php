<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/** Delete a provider that no external charge refers to. POST JSON: { id } */

$id = Http::int(Http::body(), 'id');
if ($id <= 0) {
    Http::fail('Ugyldigt id');
}

$db    = DatabaseManager::getChargesDb();
$count = ProviderRepository::usageCount($db, $id);
if ($count > 0) {
    Http::fail("Udbyderen er tilknyttet {$count} ladning(er) og kan ikke slettes", 409);
}
ProviderRepository::delete($db, $id);
CacheHelper::invalidate(['providers']);
Http::send(['success' => true]);
