<?php
require 'includes/configuration.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id']) || !isset($data['vehicleId'])) {
    echo json_encode(['success' => false, 'error' => 'Manglende parametre']);
    exit;
}

try {
    $db = new SQLite3($dbPath);
    $id = $data['id'];  // id er tekst
    $vehicleId = intval($data['vehicleId']);

    $stmt = $db->prepare("UPDATE charges SET vehicleId = :vehicleId WHERE id = :id");
    $stmt->bindValue(':vehicleId', $vehicleId, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Intern ladning opdateret']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Fejl ved opdatering']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
