<?php
/**
 * One-time migration: reset the stuck housepowerlog sync watermark.
 *
 * Background: a previous version of sync_housepowerlog_data.php accidentally
 * wrote the current wall-clock time as last_sync_timestamp on empty runs,
 * advancing the MySQL query bound past all available records. This script
 * deletes the corrupt sync_log row so the next cron run performs a clean
 * re-sync from the most recent available MySQL data.
 *
 * Usage: php cron/reset_housepower_sync.php
 */

chdir(__DIR__ . '/..');

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';

$db = DatabaseManager::getPowerlogDb();

$before = $db->query("SELECT * FROM sync_log WHERE source = 'housepowerlog'")
             ->fetch(PDO::FETCH_ASSOC);

if ($before) {
    echo "Current sync_log row:\n";
    foreach ($before as $k => $v) {
        echo "  {$k}: {$v}\n";
    }
    $db->exec("DELETE FROM sync_log WHERE source = 'housepowerlog'");
    echo "\nRow deleted. Next cron run will re-sync from scratch.\n";
} else {
    echo "No sync_log row found for housepowerlog — nothing to reset.\n";
}
?>
