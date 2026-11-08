<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/** Rename a provider. POST JSON: { id, name } */
return static function (Request $request): Response {
    $data = $request->body();
    $id   = Http::int($data, 'id');
    $name = Http::str($data, 'name');
    if ($id <= 0 || $name === '') {
        Http::fail('Ugyldigt id eller navn');
    }

    $db = DatabaseManager::getChargesDb();
    ProviderRepository::update($db, $id, $name);
    CacheHelper::invalidate(['providers']);
    return Response::json(['success' => true]);
};
