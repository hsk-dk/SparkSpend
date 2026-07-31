<?php
/**
 * Fix: Interpolate frozen sensor readings in powerloghus for Jan/Feb 2025
 *
 * PROBLEM
 * -------
 * The house power sensor (Zigbee energy meter) froze on 2025-01-08 at
 * 28771.49 kWh and resumed on 2025-02-12 at 30941.84 kWh. During the
 * freeze period, the sensor reported the same value every hour, producing
 * zero daily deltas for Jan 8–Feb 11 and a single massive delta on Feb 12.
 *
 * FIX
 * ---
 * Replace the frozen readings (constant 28771.49) with linearly interpolated
 * values between the last known-good reading and the first post-freeze reading.
 * This distributes the ~2170 kWh evenly across the 35-day gap.
 *
 * USAGE
 * -----
 *   php cron/fix_sensor_stall_2025.php           # dry-run (shows what would change)
 *   php cron/fix_sensor_stall_2025.php --commit  # apply changes
 */

chdir(__DIR__ . '/..');
require 'includes/configuration.php';
require 'includes/DatabaseManager.php';

$commit = in_array('--commit', $argv ?? [], true);
$db     = DatabaseManager::getPowerlogDb();

// Known stall parameters (from investigation)
$freezeValue   = 28771.49;   // kWh value the sensor was stuck on
$freezeStart   = '2025-01-08 00:00:00'; // first hour with frozen value
$resumeReading = '2025-02-12 18:00:00'; // first hour with new value
$resumeKwh     = 30941.84;   // first post-freeze kWh reading

// Last known-good reading before the freeze
$stmt = $db->prepare("SELECT logdate, kwh FROM powerloghus WHERE logdate < ? AND kwh < ? ORDER BY logdate DESC LIMIT 1");
$stmt->execute([$freezeStart, $freezeValue + 0.01]);
$lastGood = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lastGood) {
    // Fall back to last reading before freeze with different value
    $stmt = $db->prepare("SELECT logdate, kwh FROM powerloghus WHERE logdate < ? ORDER BY logdate DESC LIMIT 1");
    $stmt->execute([$freezeStart]);
    $lastGood = $stmt->fetch(PDO::FETCH_ASSOC);
}

echo ($commit ? "COMMIT MODE" : "DRY-RUN (no changes)") . "\n";
echo str_repeat('-', 60) . "\n";

if (!$lastGood) {
    echo "ERROR: Could not find last-good reading before freeze.\n";
    exit(1);
}

echo "Last good reading: {$lastGood['logdate']} = {$lastGood['kwh']} kWh\n";
echo "Freeze value:      {$freezeValue} kWh\n";
echo "Resume reading:    {$resumeReading} = {$resumeKwh} kWh\n";

// Find all rows in the freeze period (where kwh ≈ freezeValue)
$stmt = $db->prepare("
    SELECT logdate, kwh FROM powerloghus
    WHERE logdate >= ? AND logdate < ?
    AND kwh BETWEEN ? AND ?
    ORDER BY logdate
");
$tolerance = 0.1;
$stmt->execute([$freezeStart, $resumeReading, $freezeValue - $tolerance, $freezeValue + $tolerance]);
$frozenRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalFrozen = count($frozenRows);
echo "Frozen rows to interpolate: {$totalFrozen}\n";

if ($totalFrozen === 0) {
    echo "No frozen rows found — nothing to fix.\n";
    exit(0);
}

// Calculate interpolation
$startKwh  = floatval($lastGood['kwh']);
$endKwh    = $resumeKwh;
$totalDelta = $endKwh - $startKwh;
$startTs   = strtotime($lastGood['logdate']);
$endTs     = strtotime($resumeReading);
$totalSecs = $endTs - $startTs;

echo "Total kWh to distribute: " . round($totalDelta, 2) . "\n";
echo "Time span: " . round($totalSecs / 3600, 1) . " hours\n";
echo "Average rate: " . round($totalDelta / ($totalSecs / 3600), 3) . " kWh/hour\n";
echo str_repeat('-', 60) . "\n";

// Show sample of first and last few interpolated values
$updateStmt = $commit ? $db->prepare("UPDATE powerloghus SET kwh = ? WHERE logdate = ?") : null;

if ($commit) {
    $db->beginTransaction();
}

$updated = 0;
foreach ($frozenRows as $i => $row) {
    $rowTs   = strtotime($row['logdate']);
    $elapsed = $rowTs - $startTs;
    $ratio   = $elapsed / $totalSecs;
    $newKwh  = round($startKwh + ($totalDelta * $ratio), 6);

    // Show first 3 and last 3
    if ($i < 3 || $i >= $totalFrozen - 3) {
        printf("  %s: %.2f → %.2f\n", $row['logdate'], $row['kwh'], $newKwh);
    } elseif ($i === 3) {
        echo "  ... (" . ($totalFrozen - 6) . " more rows) ...\n";
    }

    if ($commit) {
        $updateStmt->execute([$newKwh, $row['logdate']]);
        $updated++;
    }
}

if ($commit) {
    $db->commit();
    // Invalidate caches
    $cacheDir = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_*.json') ?: [] as $f) {
        @unlink($f);
    }
    echo str_repeat('-', 60) . "\n";
    echo "Updated {$updated} rows. Caches invalidated.\n";
} else {
    echo str_repeat('-', 60) . "\n";
    echo "{$totalFrozen} rows would be updated.\n";
    echo "\nRun with --commit to apply:\n";
    echo "  php cron/fix_sensor_stall_2025.php --commit\n";
}
