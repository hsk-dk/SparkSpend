<?php
/**
 * Heat pump power log table (cumulative kWh meter, hourly readings).
 * Data synced from MySQL via cron/sync_heatpump_data.php.
 */
return [
    'description' => 'Powerlogjord table (heat pump cumulative meter)',
    'up' => [
        "CREATE TABLE IF NOT EXISTS powerlogjord (
            logdate TEXT PRIMARY KEY,
            kwh REAL NOT NULL
        )",
    ],
];
