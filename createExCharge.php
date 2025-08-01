<?php
require 'includes/configuration.php';
header('Content-Type: application/json');

$db = new SQLite3($dbPath);

$vehicleId = isset($_POST['vehicleId']) ? $_POST['vehicleId'] : '';
$providerId = isset($_POST['providerId']) ? $_POST['providerId'] : '';
$chargeDate = isset($_POST['chargeDateTime']) ? $_POST['chargeDateTime'] : '';
$chargeDate = new DateTime($chargeDate);
$chargeDate = $chargeDate->format('Y-m-d\TH:i:00\Z');
$kwh = isset($_POST['kwh']) ? $_POST['kwh'] : '';
$pris = isset($_POST['pris']) ? $_POST['pris'] : '';

if (!$vehicleId || !$providerId || !$chargeDate || !$kwh || !$pris) {
    echo json_encode(['success' => false, 'error' => 'Alle felter skal udfyldes']);
    exit;
}

$stmt = $db->prepare("INSERT INTO ext_charges (vehicleId, providerId, datetime, kwh, pris) VALUES (:vehicleId, :providerId, :datetime, :kwh, :pris)");
$stmt->bindValue(':vehicleId', intval($vehicleId), SQLITE3_INTEGER);
$stmt->bindValue(':providerId', intval($providerId), SQLITE3_INTEGER);
$stmt->bindValue(':datetime', $chargeDate);
$stmt->bindValue(':kwh', floatval($kwh));
$stmt->bindValue(':pris', floatval($pris));

$result = $stmt->execute();

if ($result) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Fejl ved oprettelse']);
}
?>
