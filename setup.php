<?php
$db = new SQLite3('charging_data.db');

$db->exec("CREATE TABLE IF NOT EXISTS vehicle_charges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicleName TEXT NOT NULL,
    cablePluggedInAt TEXT NOT NULL
)");
?>
