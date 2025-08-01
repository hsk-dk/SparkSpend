<?php
// Modtag data fra POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Kontrollér, om nødvendige data er til stede
    if (isset($_POST['charge_id']) && isset($_POST['vehicle_name'])) {
        $charge_id = $_POST['charge_id'];  // Ladningens ID
        $vehicle_name = $_POST['vehicle_name'];  // Valgt bilkategori

        // Forbind til SQLite
        $db = new SQLite3('charging_data.db');

        // Start en transaktion for at sikre atomar opdatering
        $db->exec('BEGIN TRANSACTION');

        // Forbered og udfør opdatering
        $stmt = $db->prepare("UPDATE charges SET vehicleName = :vehicle_name WHERE id = :charge_id");
        $stmt->bindValue(':vehicle_name', $vehicle_name, SQLITE3_TEXT);
        $stmt->bindValue(':charge_id', $charge_id, SQLITE3_INTEGER);
        $result = $stmt->execute();

        // Afslut transaktionen
        $db->exec('COMMIT');

        // Send svar tilbage til frontend
        if ($result) {
            echo "Kategorisering gemt!";
        } else {
            echo "Fejl ved gemning af kategorisering.";
        }
    } else {
        echo "Manglende data!";
    }
}
?>
