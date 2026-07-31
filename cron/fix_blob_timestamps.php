<?php
/**
 * fix_blob_timestamps.php
 *
 * One-time migration: Older PHP/PDO-SQLite versions stored string values as BLOB
 * instead of TEXT, causing MAX(stoppedAt) to return a BLOB value (Feb 2026) that
 * sorts above all TEXT values (SQLite sort order: BLOB > TEXT). This makes the
 * Monta sync re-process the same charges every run.
 *
 * Run once on the server: php cron/fix_blob_timestamps.php
 */

require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';

$db = DatabaseManager::getChargesDb();

$columns = ['stoppedAt', 'startedAt', 'cablePluggedInAt', 'createdAt', 'updatedAt'];

$db->exec('BEGIN');
try {
    foreach ($columns as $col) {
        $count = (int) $db->query("SELECT COUNT(*) FROM charges WHERE typeof($col) = 'blob'")->fetchColumn();
        if ($count > 0) {
            $db->exec("UPDATE charges SET $col = CAST($col AS TEXT) WHERE typeof($col) = 'blob'");
            echo "charges.$col: fixed $count BLOB rows\n";
        } else {
            echo "charges.$col: ok (no BLOB values)\n";
        }
    }

    // Also fix vehicle_charges.cablePluggedInAt if affected
    $count = (int) $db->query("SELECT COUNT(*) FROM vehicle_charges WHERE typeof(cablePluggedInAt) = 'blob'")->fetchColumn();
    if ($count > 0) {
        $db->exec("UPDATE vehicle_charges SET cablePluggedInAt = CAST(cablePluggedInAt AS TEXT) WHERE typeof(cablePluggedInAt) = 'blob'");
        echo "vehicle_charges.cablePluggedInAt: fixed $count BLOB rows\n";
    } else {
        echo "vehicle_charges.cablePluggedInAt: ok (no BLOB values)\n";
    }

    $db->exec('COMMIT');
    echo "\nMigration completed successfully.\n";

    // Verify
    $maxStopped = $db->query("SELECT MAX(stoppedAt) FROM charges")->fetchColumn();
    echo "MAX(stoppedAt) after migration: $maxStopped\n";
} catch (Exception $e) {
    $db->exec('ROLLBACK');
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
