<?php
header('Content-Type: text/plain; charset=utf-8');

try {
    // Forbind til SQLite-databasen
    $db = new SQLite3('data/charging_data.db');
    
    echo "=== DATABASE DEBUG ===\n\n";
    
    // Vis alle tabeller
    echo "TABELLER:\n";
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        echo "- " . $row['name'] . "\n";
    }
    
    echo "\n";
    
    // Vis antal rækker i hver tabel
    $tables = ['vehicles', 'charges', 'ext_charges', 'vehicle_charges'];
    foreach ($tables as $table) {
        $result = $db->query("SELECT COUNT(*) as count FROM $table");
        if ($result) {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            echo "Antal rækker i $table: " . $row['count'] . "\n";
        } else {
            echo "Fejl ved læsning af $table: " . $db->lastErrorMsg() . "\n";
        }
    }
    
    echo "\n";
    
    // Vis kolonner i hver tabel
    foreach ($tables as $table) {
        echo "KOLONNER i $table:\n";
        $result = $db->query("PRAGMA table_info($table)");
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                echo "  " . $row['name'] . " (" . $row['type'] . ")\n";
            }
        } else {
            echo "  Fejl: " . $db->lastErrorMsg() . "\n";
        }
        echo "\n";
    }
    
    // Vis eksempler fra vehicle_charges
    echo "EKSEMPEL DATA fra vehicle_charges (første 5 rækker):\n";
    $result = $db->query("SELECT * FROM vehicle_charges ORDER BY cablePluggedInAt DESC LIMIT 5");
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            echo "vehicleId: " . $row['vehicleId'] . ", cablePluggedInAt: " . $row['cablePluggedInAt'] . ", odometer: " . $row['odometer'] . "\n";
        }
    } else {
        echo "Fejl: " . $db->lastErrorMsg() . "\n";
    }
    
    echo "\n";
    
    // Vis eksempler fra charges
    echo "EKSEMPEL DATA fra charges (første 5 rækker):\n";
    $result = $db->query("SELECT * FROM charges ORDER BY createdAt DESC LIMIT 5");
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            echo "vehicleId: " . $row['vehicleId'] . ", createdAt: " . $row['createdAt'] . ", consumedKwh: " . $row['consumedKwh'] . "\n";
        }
    } else {
        echo "Fejl: " . $db->lastErrorMsg() . "\n";
    }
    
    echo "\n";
    
    // Vis eksempler fra ext_charges
    echo "EKSEMPEL DATA fra ext_charges (første 5 rækker):\n";
    $result = $db->query("SELECT * FROM ext_charges ORDER BY datetime DESC LIMIT 5");
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            echo "vehicleId: " . $row['vehicleId'] . ", datetime: " . $row['datetime'] . ", kwh: " . $row['kwh'] . "\n";
        }
    } else {
        echo "Fejl: " . $db->lastErrorMsg() . "\n";
    }
    
} catch (Exception $e) {
    echo "FEJL: " . $e->getMessage() . "\n";
}
?>
