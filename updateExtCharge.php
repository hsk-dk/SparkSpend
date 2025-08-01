<?php
require 'includes/configuration.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id']) || !isset($data['vehicleId']) || !isset($data['providerId']) || !isset($data['datetime']) || !isset($data['kwh']) || !isset($data['pris'])) {
    echo json_encode(['success' => false, 'error' => 'Manglende parametre']);
    exit;
}

try {
    $db = new SQLite3($dbPath);
    $id = intval($data['id']);
    $vehicleId = intval($data['vehicleId']);
    $providerId = intval($data['providerId']);
    $datetime = new DateTime($data['datetime']);
	$datetime = $datetime->format('Y-m-d\TH:i:00\Z');
    $kwh = floatval($data['kwh']);
    $pris = floatval($data['pris']);

    $stmt = $db->prepare("UPDATE ext_charges SET vehicleId = :vehicleId, providerId = :providerId, datetime = :datetime, kwh = :kwh, pris = :pris WHERE id = :id");
    $stmt->bindValue(':vehicleId', $vehicleId, SQLITE3_INTEGER);
    $stmt->bindValue(':providerId', $providerId, SQLITE3_INTEGER);
    $stmt->bindValue(':datetime', $datetime);
    $stmt->bindValue(':kwh', $kwh);
    $stmt->bindValue(':pris', $pris);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Ekstern ladning opdateret']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Fejl ved opdatering']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
