<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Update an external charge.
 * POST JSON: id, vehicleId, providerId, datetime (ISO), kwh, pris
 */
require_once __DIR__ . '/../includes/ChargeInput.php';

return static function (Request $request): Response {
    $data       = $request->body();
    $id         = Http::int($data, 'id');
    $vehicleId  = Http::int($data, 'vehicleId');
    $providerId = Http::int($data, 'providerId');

    if ($id <= 0 || $vehicleId <= 0 || !isset($data['datetime'])) {
        Http::fail('Missing required fields');
    }
    $v = ChargeInput::validate($data, 'datetime', 'Invalid date format');

    $db = DatabaseManager::getChargesDb();
    if (!Db::rowExists($db, 'vehicles', $vehicleId)) {
        Http::fail('Ukendt vehicleId', 422);
    }
    if ($providerId > 0 && !Db::rowExists($db, 'providers', $providerId)) {
        Http::fail('Ukendt providerId', 422);
    }
    if (!Db::rowExists($db, 'ext_charges', $id)) {
        Http::fail('Ladning ikke fundet', 404);
    }

    $ok = ChargeRepository::updateExternal($db, [
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
    CacheHelper::invalidate(['charges']);
    return Response::json(['success' => true, 'message' => 'External charge updated']);
};
