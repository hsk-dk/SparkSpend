<?php
/**
 * Daily energy of individual devices that Home Assistant measures (a sub-meter on the shed heater, the
 * freezer, ...). The house meter has no idea what is behind it; these rows let "Restforbrug" name its parts.
 * One row per (day, device), written by receive_device_energy.php, idempotent per day.
 */
return [
    'description' => 'Add device_energy (daily kWh per device, sent by Home Assistant)',
    'up' => [
        "CREATE TABLE IF NOT EXISTS device_energy (
            day        TEXT NOT NULL,      -- local date, YYYY-MM-DD
            device     TEXT NOT NULL,      -- key, e.g. varme_skur
            kwh        REAL NOT NULL,
            updated_at TEXT NOT NULL,      -- UTC (Z)
            PRIMARY KEY (day, device)
        )",
        "CREATE INDEX IF NOT EXISTS idx_device_energy_device_day ON device_energy (device, day)",
    ],
];
