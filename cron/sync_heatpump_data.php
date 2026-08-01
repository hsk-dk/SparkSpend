<?php
/**
 * Heat Pump Data Sync Cron Job
 *
 * Periodically syncs heat pump data from MySQL server to local SQLite database.
 * Runs every 5 minutes via system cron job.
 *
 * Usage: php cron/sync_heatpump_data.php
 */

// Set working directory and include dependencies
require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';
require __DIR__ . '/../includes/MySQLManager.php';

// Prevent concurrent syncs with lock file
$lockFile = __DIR__ . '/sync_heatpump.lock';
$maxLockAge = 60; // seconds

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < $maxLockAge) {
        logMessage("Sync already in progress (lock file age: {$lockAge}s)", 'INFO');
        exit(0);
    } else {
        // Lock file is stale, remove it
        unlink($lockFile);
        logMessage("Removed stale lock file (age: {$lockAge}s)", 'WARN');
    }
}

// Create lock file
touch($lockFile);

try {
    // Connect to databases
    $mysqlDb = MySQLManager::getHeatpumpDb();
    $sqliteDb = DatabaseManager::getPowerlogDb();

    logMessage("Starting heat pump data sync...", 'INFO');

    // Get last sync timestamp from SQLite
    $lastSync = getLastSyncTimestamp($sqliteDb);
    logMessage("Last sync: " . ($lastSync ? $lastSync : 'Never'), 'INFO');

    // Fetch new/updated records from MySQL
    $newRecords = fetchNewHeatpumpData($mysqlDb, $lastSync);
    $recordCount = count($newRecords);

    if ($recordCount === 0) {
        logMessage("No new records to sync", 'INFO');
    } else {
        logMessage("Found {$recordCount} new records to sync", 'INFO');

        // Insert/update records in SQLite
        $inserted = syncHeatpumpData($sqliteDb, $newRecords);

        logMessage("Successfully synced {$inserted} records", 'INFO');

        // Update sync timestamp based on MAX logdate from the records we just synced
        updateSyncTimestamp($sqliteDb, $recordCount, $newRecords);
    }

    logMessage("Heat pump data sync completed successfully", 'INFO');

} catch (Exception $e) {
    logMessage("Sync failed: " . $e->getMessage(), 'ERROR');
    recordSyncError($sqliteDb, $e->getMessage());
} finally {
    // Remove lock file
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// ==================== Helper Functions ====================

/**
 * Get timestamp of last successful sync
 *
 * @param PDO $sqliteDb SQLite database connection
 * @return string|null Last sync timestamp or null if never synced
 */
function getLastSyncTimestamp(PDO $sqliteDb): ?string {
    try {
        $stmt = $sqliteDb->prepare("SELECT last_sync_timestamp FROM sync_log WHERE source = 'heatpump'");
        $stmt->execute();
        $result = $stmt->fetch();

        return $result ? $result['last_sync_timestamp'] : null;
    } catch (Exception $e) {
        logMessage("Warning: Could not read sync timestamp: " . $e->getMessage(), 'WARN');
        // If table doesn't exist, return null to sync all data
        return null;
    }
}

/**
 * Fetch new heat pump records from MySQL
 *
 * @param PDO $mysqlDb MySQL database connection
 * @param string|null $afterTimestamp Only fetch records after this timestamp
 * @return array Array of heat pump records
 * @throws Exception If query fails
 */
function fetchNewHeatpumpData(PDO $mysqlDb, ?string $afterTimestamp): array {
    $tableName = Config::get('mysqlHeatpumpTable', 'powerlog');
    $allRecords = [];

    // Use larger batch for initial sync (no afterTimestamp), smaller for incremental
    $batchSize = $afterTimestamp ? 1000 : 50000;  // 50k records for bulk sync, 1k for incremental
    $offset = 0;

    try {
        while (true) {
            if ($afterTimestamp) {
                // Fetch only new aggregated hourly records since last sync
                // Group by hour and take MAX kwh value (since kwh is cumulative)
                $query = "
                    SELECT
                        DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00') as logdate,
                        MAX(kwh) as kwh
                    FROM {$tableName}
                    WHERE logdate > ?
                    GROUP BY DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00')
                    ORDER BY logdate ASC
                    LIMIT {$batchSize} OFFSET {$offset}
                ";
                $stmt = $mysqlDb->prepare($query);
                $stmt->execute([$afterTimestamp]);
            } else {
                // First sync - fetch all records aggregated to hourly
                // Group by hour and take MAX kwh value
                $query = "
                    SELECT
                        DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00') as logdate,
                        MAX(kwh) as kwh
                    FROM {$tableName}
                    GROUP BY DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00')
                    ORDER BY logdate ASC
                    LIMIT {$batchSize} OFFSET {$offset}
                ";
                $stmt = $mysqlDb->prepare($query);
                $stmt->execute();
            }

            $records = $stmt->fetchAll();

            if (empty($records)) {
                break;  // No more records
            }

            $allRecords = array_merge($allRecords, $records);
            $offset += $batchSize;

            // Memory check - if we've accumulated too many records, return what we have
            // This ensures we don't exhaust memory even with large aggregated datasets
            // Batch limit is higher for initial sync (100k) than incremental (10k)
            $memoryLimit = $afterTimestamp ? 10000 : 100000;
            if (count($allRecords) >= $memoryLimit) {
                logMessage("Notice: Returning " . count($allRecords) . " aggregated hourly records (batch limit reached, will continue on next sync)", 'INFO');
                break;
            }
        }

        return $allRecords;

    } catch (PDOException $e) {
        throw new Exception("Failed to query MySQL: " . $e->getMessage());
    }
}

/**
 * Sync heat pump records to SQLite database
 *
 * @param PDO $sqliteDb SQLite database connection
 * @param array $records Records to sync
 * @return int Number of records inserted/updated
 * @throws Exception If sync fails
 */
function syncHeatpumpData(PDO $sqliteDb, array $records): int {
    $inserted  = 0;
    $batchSize = 1000;

    $stmt = $sqliteDb->prepare("
        INSERT OR REPLACE INTO powerlogjord (logdate, kwh)
        VALUES (?, ?)
    ");

    $sqliteDb->beginTransaction();
    try {
        foreach ($records as $record) {
            $logdate = $record['datetime'] ?? $record['logdate'] ?? $record['timestamp'];
            $kwh = $record['kwh'] ?? $record['value'] ?? 0;

            try {
                $stmt->execute([$logdate, floatval($kwh)]);
                $inserted++;
            } catch (PDOException $e) {
                logMessage("Warning: Could not insert record with logdate={$logdate}: " . $e->getMessage(), 'WARN');
            }

            // Commit in chunks to avoid holding the write-lock too long
            if ($inserted > 0 && $inserted % $batchSize === 0) {
                $sqliteDb->commit();
                $sqliteDb->beginTransaction();
            }
        }
        $sqliteDb->commit();
    } catch (\Throwable $e) {
        $sqliteDb->rollBack();
        throw new Exception("Failed to sync records to SQLite: " . $e->getMessage());
    }

    return $inserted;
}

/**
 * Update sync timestamp in SQLite
 *
 * @param PDO $sqliteDb SQLite database connection
 * @param int $count Number of records synced
 * @param array $records The records that were just synced (to get max logdate)
 * @return void
 */
function updateSyncTimestamp(PDO $sqliteDb, int $count, array $records = []): void {
    try {
        // For the initial bulk load, use the MAX logdate from the synced records
        // This ensures the next sync continues from where this one left off
        // For incremental syncs with few records, this still works fine
        if (!empty($records)) {
            // Find the maximum logdate from the synced records
            $maxLogdate = null;
            foreach ($records as $record) {
                $logdate = $record['logdate'] ?? $record['datetime'] ?? $record['timestamp'] ?? null;
                if ($logdate && ($maxLogdate === null || $logdate > $maxLogdate)) {
                    $maxLogdate = $logdate;
                }
            }

            // Use the max logdate from records, or fall back to current time
            $syncTimestamp = $maxLogdate ?? date('Y-m-d H:i:s');
        } else {
            $syncTimestamp = date('Y-m-d H:i:s');
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $sqliteDb->prepare("
            INSERT OR REPLACE INTO sync_log (source, last_sync_timestamp, last_sync_count, updated_at)
            VALUES ('heatpump', ?, ?, ?)
        ");

        $stmt->execute([$syncTimestamp, $count, $now]);

    } catch (Exception $e) {
        logMessage("Warning: Could not update sync timestamp: " . $e->getMessage(), 'WARN');
    }
}

/**
 * Record sync error in database
 *
 * @param PDO $sqliteDb SQLite database connection
 * @param string $error Error message
 * @return void
 */
function recordSyncError(PDO $sqliteDb, string $error): void {
    try {
        $now = date('Y-m-d H:i:s');

        $stmt = $sqliteDb->prepare("
            INSERT OR REPLACE INTO sync_log (source, error_message, updated_at)
            VALUES ('heatpump', ?, ?)
        ");

        $stmt->execute([$error, $now]);

    } catch (Exception $e) {
        logMessage("Warning: Could not record sync error: " . $e->getMessage(), 'WARN');
    }
}

/**
 * Log message to cron.log
 *
 * @param string $message Message to log
 * @param string $level Log level (INFO, WARN, ERROR)
 * @return void
 */
function logMessage(string $message, string $level = 'INFO'): void {
    $logFile = __DIR__ . '/cron.log';
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] Heat Pump Sync: {$message}\n";

    // Append to log file
    file_put_contents($logFile, $logLine, FILE_APPEND);

    // Also log to PHP error log for visibility
    if ($level === 'ERROR') {
        error_log("Heat Pump Sync: {$message}");
    }
}

?>
