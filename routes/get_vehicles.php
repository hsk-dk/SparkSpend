<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Get all vehicles
 * Returns a JSON array of all registered vehicles
 */
return static function (Request $request): Response {
    $db = DatabaseManager::getChargesDb();
    return Response::json(VehicleRepository::getAll($db));
};
