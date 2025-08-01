<?php
require 'includes/configuration.php';
header('Content-Type: application/json');

try {
    $db = new PDO('sqlite:'.$dbPath); // Sørg for at dette stemmer med din database
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT * FROM provideres");
    $stmt->execute();
    $provideres = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($provideres);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Databasefejl: ' . $e->getMessage()]);
}
?>
