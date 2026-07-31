<?php
/**
 * House Power Log Sync Cron Job
 *
 * Periodically syncs whole-house electricity meter data from the MySQL
 * `powerloghus` table to a local SQLite `powerloghus` table in powerlog_data.db.
 * Runs every 5–15 minutes via system cron job.
 *
 * Unit normalisation applied during sync:
 *   The source data sometimes stores cumulative energy in Wh instead of kWh
 *   (data-logger bug causing values ~1000× too large).  Any hourly MAX(kwh)
 *   value above 100,000 is divided by 1 000 and assumed to be in Wh.
 *   Legitimate cumulative readings should not exceed ~100,000 kWh for a
 *   residential meter within the expected operational lifetime of this system.
 *
 * Usage: php cron/sync_housepowerlog_data.php
 */

chdir(__DIR__ . '/..');

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/MySQLManager.php';

// ── Lock file ──────────────────────────────────────────────────────────────
$lockFile   = __DIR__ . '/sync_housepowerlog.lock';
$maxLockAge = 60; // seconds

$runUser = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();
logMsg("Invoked (pid=" . getmypid() . ", user={$runUser})");

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < $maxLockAge) {
        logMsg("Sync already in progress (lock age: {$lockAge}s)");
        exit(0);
    }
    unlink($lockFile);
    logMsg("Removed stale lock file (age: {$lockAge}s)", 'WARN');
}
touch($lockFile);

// ── Main ───────────────────────────────────────────────────────────────────
try {
    $mysqlDb  = MySQLManager::getHeatpumpDb();   // powerloghus lives in same DB
    $sqliteDb = DatabaseManager::getPowerlogDb();

    logMsg("Starting house power log sync…");

    ensureTableExists($sqliteDb);

    $lastSync = getLastSync($sqliteDb);
    logMsg("Last sync: " . ($lastSync ?? 'Never'));

    $records = fetchRecords($mysqlDb, $lastSync);
    $count   = count($records);

    if ($count === 0) {
        logMsg("No new records to sync.");
    } else {
        logMsg("Fetched {$count} records to sync.");
        $inserted = insertRecords($sqliteDb, $records);
        logMsg("Inserted/updated {$inserted} records.");
    }

    // Always update sync_log so getSyncStatus.php reflects when the script last ran,
    // even on runs where no new records were found.
    saveLastSync($sqliteDb, $count, $records);

    logMsg("House power log sync completed.");

} catch (Exception $e) {
    logMsg("Sync failed: " . $e->getMessage(), 'ERROR');
} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────

/**
 * Create the SQLite powerloghus table if it does not exist.
 * Schema is now managed by migrations, but kept as a safety check.
 */
function ensureTableExists(PDO $db): void {
    // No-op: table creation is handled by migrations/powerlog/002_add_powerloghus.php
    // The migration runner runs on DatabaseManager::connect(), so the table exists
    // by the time this function is called.
}

/**
 * Return the last successfully synced timestamp, or null on first run.
 */
function getLastSync(PDO $db): ?string {
    try {
        $stmt = $db->prepare(
            "SELECT last_sync_timestamp FROM sync_log WHERE source = 'housepowerlog'"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? $row['last_sync_timestamp'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Fetch hourly-aggregated records from MySQL, applying unit normalisation.
 *
 * Normalisation rule:
 *   If MAX(kwh) for an hour bucket exceeds 100,000, the value is assumed to
 *   have been stored in Wh by the data logger, and is divided by 1,000 to
 *   convert to kWh before storage in SQLite.
 */
function fetchRecords(PDO $mysqlDb, ?string $afterTimestamp): array {
    $table     = $GLOBALS['mysqlHousePowerTable'] ?? 'powerloghus';
    $batchSize = $afterTimestamp ? 1000 : 50000;
    $offset    = 0;
    $all       = [];

    $normalise = "CASE
                      WHEN MAX(kwh) > 100000 THEN ROUND(MAX(kwh) / 1000.0, 2)
                      ELSE MAX(kwh)
                  END";

    try {
        while (true) {
            if ($afterTimestamp) {
                $sql = "
                    SELECT DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00') AS logdate,
                           {$normalise} AS kwh
                    FROM `{$table}`
                    WHERE logdate > ?
                    GROUP BY DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00')
                    ORDER BY logdate ASC
                    LIMIT {$batchSize} OFFSET {$offset}
                ";
                $stmt = $mysqlDb->prepare($sql);
                $stmt->execute([$afterTimestamp]);
            } else {
                $sql = "
                    SELECT DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00') AS logdate,
                           {$normalise} AS kwh
                    FROM `{$table}`
                    GROUP BY DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00')
                    ORDER BY logdate ASC
                    LIMIT {$batchSize} OFFSET {$offset}
                ";
                $stmt = $mysqlDb->prepare($sql);
                $stmt->execute();
            }

            $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($batch)) break;

            $all    = array_merge($all, $batch);
            $offset += $batchSize;

            $memLimit = $afterTimestamp ? 10000 : 100000;
            if (count($all) >= $memLimit) {
                logMsg("Batch limit reached at " . count($all) . " records — will resume on next run.", 'INFO');
                break;
            }
        }
    } catch (PDOException $e) {
        throw new Exception("MySQL query failed: " . $e->getMessage());
    }

    return $all;
}

/**
 * Insert/replace records into SQLite powerloghus.
 */
function insertRecords(PDO $db, array $records): int {
    $stmt      = $db->prepare("INSERT OR REPLACE INTO powerloghus (logdate, kwh) VALUES (?, ?)");
    $inserted  = 0;
    $batchSize = 1000;

    $db->beginTransaction();
    try {
        foreach ($records as $row) {
            $logdate = $row['logdate'] ?? null;
            $kwh     = $row['kwh']     ?? null;
            if ($logdate === null || $kwh === null) continue;
            try {
                $stmt->execute([$logdate, floatval($kwh)]);
                $inserted++;
            } catch (PDOException $e) {
                logMsg("Could not insert logdate={$logdate}: " . $e->getMessage(), 'WARN');
            }

            // Commit in chunks to avoid holding the write-lock too long
            if ($inserted > 0 && $inserted % $batchSize === 0) {
                $db->commit();
                $db->beginTransaction();
            }
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $inserted;
}

/**
 * Persist sync state after each run.
 *
 * last_sync_timestamp = watermark: max logdate of the last batch with actual records.
 *   Only updated when count > 0 so the MySQL query bound doesn't advance past real data.
 *
 * updated_at = run timestamp: always written so getSyncStatus.php can show when the
 *   script last executed, even on runs where no new records were found.
 */
function saveLastSync(PDO $db, int $count, array $records): void {
    try {
        $now = date('Y-m-d H:i:s');

        if ($count > 0) {
            // Advance watermark to the max logdate of the batch just inserted.
            $maxLogdate = null;
            foreach ($records as $row) {
                $ld = $row['logdate'] ?? null;
                if ($ld && ($maxLogdate === null || $ld > $maxLogdate)) {
                    $maxLogdate = $ld;
                }
            }
            $stmt = $db->prepare("
                INSERT OR REPLACE INTO sync_log
                    (source, last_sync_timestamp, last_sync_count, updated_at)
                VALUES ('housepowerlog', ?, ?, ?)
            ");
            $stmt->execute([$maxLogdate ?? $now, $count, $now]);
        } else {
            // No new data: read the existing watermark so we can preserve it with
            // INSERT OR REPLACE (avoids the ON CONFLICT DO UPDATE syntax that requires
            // SQLite 3.24+, which is not available on all servers).
            $existing = $db->prepare("SELECT last_sync_timestamp FROM sync_log WHERE source = 'housepowerlog'");
            $existing->execute();
            $row       = $existing->fetch();
            $watermark = $row ? $row['last_sync_timestamp'] : null;

            $stmt = $db->prepare("
                INSERT OR REPLACE INTO sync_log
                    (source, last_sync_timestamp, last_sync_count, updated_at)
                VALUES ('housepowerlog', ?, 0, ?)
            ");
            $stmt->execute([$watermark, $now]);
        }
    } catch (Exception $e) {
        logMsg("Could not update sync timestamp: " . $e->getMessage(), 'WARN');
    }
}

/**
 * Append a timestamped line to cron/cron.log.
 */
function logMsg(string $message, string $level = 'INFO'): void {
    $logFile = __DIR__ . '/cron.log';
    $line    = '[' . date('Y-m-d H:i:s') . "] [{$level}] House Power Sync: {$message}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    if ($level === 'ERROR') {
        error_log("House Power Sync: {$message}");
    }
}
?>
