<?php
/**
 * Migration: Convert charges table timestamps to UTC Z-suffix format
 *
 * PROBLEM
 * -------
 * The Monta API returns timestamps with timezone offsets (e.g. +01:00, +02:00).
 * These were stored as-is in the charges table. While SQLite's DATE() handles
 * both formats, strftime() normalizes offsets to UTC but leaves Z as-is, causing
 * inconsistency. Standardizing all timestamps to UTC Z-suffix ensures:
 *   - Consistent SQL date function behaviour
 *   - Simple string comparison for date ranges
 *   - No ambiguity between stored format and actual UTC time
 *
 * FIX
 * ---
 * For each row: parse timestamp with PHP strtotime() (handles both +HH:MM and Z),
 * convert to UTC, write back as YYYY-MM-DDTHH:MM:SSZ.
 *
 * Columns migrated: startedAt, stoppedAt, cablePluggedInAt, createdAt, updatedAt
 *
 * USAGE
 * -----
 *   php cron/migrate_charges_utc.php           # dry-run (no changes)
 *   php cron/migrate_charges_utc.php --commit  # apply changes
 */

require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';

$commit = in_array('--commit', $argv ?? [], true);
$db     = DatabaseManager::getChargesDb();

$columns = ['startedAt', 'stoppedAt', 'cablePluggedInAt', 'createdAt', 'updatedAt'];

// Fetch all rows — charges table is typically small (hundreds to low thousands)
$rows = $db->query("SELECT id, startedAt, stoppedAt, cablePluggedInAt, createdAt, updatedAt FROM charges ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Ingen rækker i charges-tabellen.\n";
    exit(0);
}

echo ($commit ? "COMMIT-TILSTAND" : "DRY-RUN (ingen ændringer)") . " — " . count($rows) . " rækker\n";
echo str_repeat('-', 90) . "\n";
printf("%-8s  %-26s  %-26s  %s\n", "ID", "Kolonne", "Gemt", "Rettet");
echo str_repeat('-', 90) . "\n";

$changed   = 0;
$skipped   = 0;
$errors    = 0;

if ($commit) {
    $db->beginTransaction();
}

$updateStmt = $commit
    ? $db->prepare("UPDATE charges SET startedAt = ?, stoppedAt = ?, cablePluggedInAt = ?, createdAt = ?, updatedAt = ? WHERE id = ?")
    : null;

foreach ($rows as $row) {
    $rowChanged = false;
    $newValues  = [];

    foreach ($columns as $col) {
        $stored = $row[$col];

        if ($stored === null || $stored === '') {
            $newValues[$col] = $stored;
            continue;
        }

        // Already in Z-suffix format — skip
        if (str_ends_with($stored, 'Z')) {
            $newValues[$col] = $stored;
            continue;
        }

        // Has timezone offset — needs conversion
        $ts = strtotime($stored);
        if ($ts === false) {
            echo "  FEJL: Kan ikke parse id={$row['id']} {$col}={$stored}\n";
            $newValues[$col] = $stored;
            $errors++;
            continue;
        }

        $corrected = gmdate('Y-m-d\TH:i:s\Z', $ts);

        if ($corrected !== $stored) {
            $rowChanged = true;
            printf("%-8s  %-26s  %-26s  %s\n", $row['id'], $col, $stored, $corrected);
        }

        $newValues[$col] = $corrected;
    }

    if ($rowChanged) {
        $changed++;
        if ($commit) {
            $updateStmt->execute([
                $newValues['startedAt'],
                $newValues['stoppedAt'],
                $newValues['cablePluggedInAt'],
                $newValues['createdAt'],
                $newValues['updatedAt'],
                $row['id'],
            ]);
        }
    } else {
        $skipped++;
    }
}

if ($commit) {
    $db->commit();
    // Invalidate all file caches
    $cacheDir = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_*.json') ?: [] as $f) {
        @unlink($f);
    }
}

echo str_repeat('-', 90) . "\n";
echo "{$changed} rækker med ændringer, {$skipped} allerede UTC, {$errors} fejl.\n";

if (!$commit && $changed > 0) {
    echo "\nKør med --commit for at anvende ændringerne:\n";
    echo "  php cron/migrate_charges_utc.php --commit\n";
}
