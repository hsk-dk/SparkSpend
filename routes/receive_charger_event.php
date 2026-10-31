<?php
/**
 * Receive an event from the charger (Home Assistant).
 *
 * POST JSON body (see includes/ChargerEvents.php for the rules):
 *   event           plugged | charge_start | charge_end | unplugged
 *   eventAt         ISO 8601 timestamp of the event (HA: the entity's last_changed)
 *   status          charger status (optional)
 *   sessionEnergy   kWh (charge_end: the session's final energy)
 *   lifetimeEnergy  kWh, power kW (optional)
 *   cars            {"<vehicleId>": {soc, odometer, cable, carTs}} snapshot (optional)
 *
 * Idempotent: the same (event, eventAt) twice is answered 200 and stored once.
 * Same API key as receive_vehicle_data.php (VEHICLE_API_KEY, fail closed).
 */
require_once __DIR__ . '/../includes/ChargerEvents.php';

$apiKey = Config::vehicleApiKey();
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Endpoint not configured — set VEHICLE_API_KEY in .env']);
    exit;
}
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($authHeader, 'Bearer ') || !hash_equals($apiKey, substr($authHeader, 7))) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="SparkSpend"');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON format']);
    exit;
}

$v = ChargerEvents::validate($input, time());
if (!$v['ok']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $v['error']]);
    exit;
}
$row = $v['row'];

$db = DatabaseManager::getChargesDb();
$stmt = $db->prepare("
    INSERT OR IGNORE INTO charger_events
        (eventAt, receivedAt, event, chargerStatus, sessionEnergy, lifetimeEnergy, power, cars)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $row['eventAt'], gmdate('Y-m-d\TH:i:s\Z'), $row['event'], $row['chargerStatus'],
    $row['sessionEnergy'], $row['lifetimeEnergy'], $row['power'], $row['cars'],
]);

if ($stmt->rowCount() > 0) {
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Event stored']);
} else {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Duplicate ignored']);
}
