<?php
/**
 * Heat Pump Sync Setup
 *
 * Initializes the sync_log table in SQLite database for tracking heat pump data syncs.
 * Run this once before the first cron job execution.
 *
 * Usage: php cron/setup_heatpump_sync.php
 */

chdir(__DIR__ . '/..');

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';

try {
    echo "Setting up heat pump data sync tracking...\n";

    $db = DatabaseManager::getPowerlogDb();

    // Create sync_log table
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS sync_log (
            source TEXT PRIMARY KEY,
            last_sync_timestamp TEXT,
            last_sync_count INTEGER,
            error_message TEXT,
            updated_at TEXT
        )
    ";

    $db->exec($createTableSQL);

    echo "✓ Created sync_log table\n";

    // Initialize heatpump sync log entry
    $stmt = $db->prepare("
        INSERT OR IGNORE INTO sync_log (source, last_sync_timestamp, last_sync_count, updated_at)
        VALUES ('heatpump', ?, 0, ?)
    ");

    $now = date('Y-m-d H:i:s');
    $stmt->execute([$now, $now]);

    echo "✓ Initialized heat pump sync log entry\n";

    echo "\nSetup completed successfully!\n";
    echo "You can now start the cron job: php cron/sync_heatpump_data.php\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

?>
