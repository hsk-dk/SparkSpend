<?php
require 'includes/configuration.php';
header('Content-Type: application/json');

try {
    // Forbind til SQLite-databasen
    $db = new SQLite3($dbPath);

    // Hent data fra frontend
    $input = json_decode(file_get_contents("php://input"), true);

    // Sikre at ID og vehicleId er modtaget
    if (!isset($input['id']) || !isset($input['vehicleId'])) {
        echo json_encode(["error" => "Manglende ID eller vehicleId"]);
        exit;
    }

    $id = (string) $input['id'];
    $vehicleId = (int) $input['vehicleId'];

    // Opdater databasen med det nye vehicleId
    $query = "UPDATE charges SET vehicleId = $vehicleId WHERE id = $id";
    $result = $db->exec($query);

    if ($result) {
        echo json_encode(["success" => true, "message" => "Ladning opdateret."]);
    } else {
        echo json_encode(["error" => "Fejl ved opdatering af ladning."]);
    }
} catch (Exception $e) {
    echo json_encode(["error" => "Databasefejl: " . $e->getMessage()]);
}
?>
