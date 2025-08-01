<?php
require 'includes/configuration.php';
header('Content-Type: application/json');

// Forudsætter, at du har oprettet forbindelse til databasen
$db = new SQLite3($dbPath);

// Hent POST-data
$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Ingen id angivet']);
    exit;
}

$id = intval($data['id']);

// Udfør sletningen
$stmt = $db->prepare('DELETE FROM ext_charges WHERE id = :id');
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$result = $stmt->execute();

if ($result) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Kunne ikke slette ladningen']);
}
?>
