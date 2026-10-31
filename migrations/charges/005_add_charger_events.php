<?php
/**
 * Events from the charger itself (Home Assistant -> receive_charger_event.php).
 *
 * One row per event: plugged | charge_start | charge_end | unplugged, with the
 * charger's own readings and a snapshot of every car (SoC, odometer, cable sensor,
 * the car's own timestamp) taken at that moment. Sessions are assembled from the
 * rows (includes/ChargerSessions.php); nothing is derived at write time.
 *
 * (event, eventAt) is unique, so a retried HA call is harmless.
 */
return [
    'description' => 'Add charger_events (charger status events + car snapshots from Home Assistant)',
    'up' => [
        "CREATE TABLE IF NOT EXISTS charger_events (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            eventAt        TEXT NOT NULL,   -- UTC (Z), the entity's last_changed in HA
            receivedAt     TEXT NOT NULL,   -- UTC (Z), server clock
            event          TEXT NOT NULL,   -- plugged | charge_start | charge_end | unplugged
            chargerStatus  TEXT,
            sessionEnergy  REAL,            -- kWh; charge_end = the session's final energy
            lifetimeEnergy REAL,            -- kWh; counter that only grows (updates ~hourly)
            power          REAL,            -- kW
            cars           TEXT,            -- JSON: {\"<vehicleId>\": {soc, odometer, cable, carTs}}
            UNIQUE (event, eventAt)
        )",
        "CREATE INDEX IF NOT EXISTS idx_charger_events_at ON charger_events (eventAt)",
    ],
];
