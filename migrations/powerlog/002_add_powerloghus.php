<?php
/**
 * House power meter table (cumulative kWh, hourly readings).
 * Data synced from MySQL via cron/sync_housepowerlog_data.php.
 */
return [
    'description' => 'Powerloghus table (house power cumulative meter)',
    'up' => [
        "CREATE TABLE IF NOT EXISTS powerloghus (
            logdate TEXT PRIMARY KEY,
            kwh REAL NOT NULL
        )",
    ],
];
