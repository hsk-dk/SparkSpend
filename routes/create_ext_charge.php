<?php
/**
 * Create an external charge record.
 * POST (JSON or form): vehicleId, providerId, chargeDateTime (ISO), kwh, pris
 */
require_once __DIR__ . '/../includes/ChargeInput.php';

$input      = Http::body();
$vehicleId  = Http::int($input, 'vehicleId');
$providerId = Http::int($input, 'providerId');
$when       = Http::str($input, 'chargeDateTime');

if ($vehicleId <= 0 || $providerId <= 0 || $when === '') {
    Http::fail('Missing required fields: vehicleId, providerId, chargeDateTime');
}
$v = ChargeInput::validate(['chargeDateTime' => $when] + $input, 'chargeDateTime', 'Invalid charge date format');

$db = DatabaseManager::getChargesDb();
$result = QueryBuilder::insertExternalCharge($db, [
    'vehicleId'  => $vehicleId,
    'providerId' => $providerId,
    'kwh'        => $v['kwh'],
    'cost'       => $v['pris'],
    'chargeDate' => $v['date'],
    'provider'   => '',
    'category'   => '',
]);

if (!$result['success']) {
    Http::fail($result['error'] ?? 'Error creating charge', 500);
}
QueryBuilder::invalidateChargeCaches();
Http::send(['success' => true, 'id' => $result['id']], 201);
