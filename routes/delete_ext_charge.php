<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/** Delete an external charge. POST JSON: { id } */
return static function (Request $request): Response {
    $data = $request->body();
    if (!isset($data['id'])) {
        Http::fail('Missing id parameter');
    }
    $id = Http::int($data, 'id');

    $db = DatabaseManager::getChargesDb();
    if (!Db::rowExists($db, 'ext_charges', $id)) {
        Http::fail('Ladning ikke fundet', 404);
    }
    if (!ChargeRepository::deleteExternal($db, $id)) {
        Http::fail('Error deleting charge', 500);
    }
    CacheHelper::invalidate(['charges']);
    return Response::json(['success' => true]);
};
