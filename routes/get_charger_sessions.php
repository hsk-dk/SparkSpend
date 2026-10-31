<?php
/**
 * Charging sessions assembled from the charger events.
 *
 * GET api.php?action=charger-sessions[&days=30]   (days: 1..365)
 *
 * Newest first. Each session has pluggedAt / chargeStartAt / chargeEndAt /
 * unpluggedAt, the charger's own energy and average power, and the car snapshots
 * taken at each event. Car attribution is deliberately NOT decided here yet.
 */
require_once __DIR__ . '/../includes/ChargerSessions.php';

$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
$days = max(1, min(365, $days));
$since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);

$db = DatabaseManager::getChargesDb();
$stmt = $db->prepare("
    SELECT eventAt, event, chargerStatus, sessionEnergy, lifetimeEnergy, power, cars
    FROM charger_events WHERE eventAt >= ? ORDER BY eventAt
");
$stmt->execute([$since]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($events as &$e) {
    $e['cars'] = $e['cars'] !== null ? json_decode($e['cars'], true) : null;
    foreach (['sessionEnergy', 'lifetimeEnergy', 'power'] as $k) {
        if ($e[$k] !== null) $e[$k] = (float) $e[$k];
    }
}
unset($e);

echo json_encode([
    'success'  => true,
    'days'     => $days,
    'events'   => count($events),
    'sessions' => array_reverse(ChargerSessions::build($events)),
]);
