<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Receive daily energy per device from Home Assistant.
 *
 * POST JSON, one day:   {"date": "2026-11-06", "devices": {"varme_skur": 12.3}}
 *      or a backfill:   {"days": [{"date": "...", "devices": {...}}, ...]}   (max 400 days)
 *
 * Idempotent: a (day, device) that is sent again replaces its value. Same API key as the other
 * Home Assistant endpoints (VEHICLE_API_KEY, fail closed). See includes/DeviceEnergy.php.
 */
require_once __DIR__ . '/../includes/ApiAuth.php';

return static function (Request $request): Response {
    ApiAuth::requireBearer(Config::vehicleApiKey(), 'VEHICLE_API_KEY', $request);
    if ($request->method !== 'POST') {
        Http::fail('Method not allowed', 405);
    }

    $input = json_decode($request->raw(), true);
    if (!is_array($input)) {
        Http::fail('Invalid JSON format');
    }

    $v = DeviceEnergy::validate($input, date('Y-m-d'));
    if (!$v['ok']) {
        Http::fail($v['error']);
    }

    $n = DeviceEnergy::store(DatabaseManager::getPowerlogDb(), $v['rows']);
    CacheHelper::invalidate(['meter']);   // the house-power / bill views include these rows
    return Response::json(['success' => true, 'message' => 'Stored', 'rows' => $n], 201);
};
