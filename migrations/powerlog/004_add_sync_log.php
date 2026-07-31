<?php
/**
 * Add sync_log table for tracking cron sync status (heatpump + house power).
 */
return [
    'description' => 'Add sync_log table for tracking cron sync status',
    'up' => [
        "CREATE TABLE IF NOT EXISTS sync_log (
            source TEXT PRIMARY KEY,
            last_sync_timestamp TEXT,
            last_sync_count INTEGER,
            updated_at TEXT,
            error_message TEXT
        )",
    ],
];
