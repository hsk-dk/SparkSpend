<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/** Create a charging provider. POST JSON: { name } */
return static function (Request $request): Response {
    $name = Http::str($request->body(), 'name');
    if ($name === '') {
        Http::fail('Navn må ikke være tomt');
    }

    $db = DatabaseManager::getChargesDb();
    $id = ProviderRepository::insert($db, $name);
    CacheHelper::invalidate(['providers']);
    return Response::json(['success' => true, 'id' => $id, 'providerName' => $name]);
};
