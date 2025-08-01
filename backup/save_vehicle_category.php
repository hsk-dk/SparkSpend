<?php
// Modtag data fra POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $charge_id = $_POST['charge_id'];  // ID for ladningen
    $vehicle_name = $_POST['vehicle_name'];  // Valgt bilnavn

    // Forbind til SQLite
    $db = new SQLite3('charging_data.db');

    // Opdater ladningen med den nye kategori
    $stmt = $db->prepare("UPDATE charges SET vehicleName = :vehicle_name WHERE id = :charge_id");
    $stmt->bindValue(':vehicle_name', $vehicle_name, SQLITE3_TEXT);
    $stmt->bindValue(':charge_id', $charge_id, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        echo "Kategorisering gemt!";
    } else {
        echo "Fejl ved gemning af kategorisering.";
    }
}
?>
