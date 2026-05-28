<?php
/**
 * SparkSpend SQLite Data Integrity Checker
 *
 * Validates the powerlog_data.db dump for corrupt or invalid data
 * Usage: php check_data_integrity.php
 */

require dirname(__DIR__) . '/includes/configuration.php';
require dirname(__DIR__) . '/includes/DatabaseManager.php';

echo "=== SparkSpend SQLite Data Integrity Check ===\n\n";

try {
    $db = DatabaseManager::getPowerlogDb();

    echo "Checking powerlogjord table...\n\n";

    // 1. Check table exists
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='powerlogjord';")->fetchAll();
    if (empty($tables)) {
        echo "✗ Table 'powerlogjord' not found!\n";
        exit(1);
    }
    echo "✓ Table exists\n";

    // 2. Total record count
    $totalCount = $db->query("SELECT COUNT(*) FROM powerlogjord")->fetchColumn();
    echo "✓ Total records: $totalCount\n\n";

    // 3. Check for NULL values
    echo "Checking for NULL values...\n";
    $nullLogdate = $db->query("SELECT COUNT(*) FROM powerlogjord WHERE logdate IS NULL")->fetchColumn();
    $nullKwh = $db->query("SELECT COUNT(*) FROM powerlogjord WHERE kwh IS NULL")->fetchColumn();

    if ($nullLogdate > 0) {
        echo "✗ Found $nullLogdate records with NULL logdate\n";
    } else {
        echo "✓ No NULL logdate values\n";
    }

    if ($nullKwh > 0) {
        echo "✗ Found $nullKwh records with NULL kwh\n";
    } else {
        echo "✓ No NULL kwh values\n";
    }
    echo "\n";

    // 4. Check for invalid dates
    echo "Checking for invalid dates...\n";
    $invalidDates = $db->query("SELECT COUNT(*) FROM powerlogjord WHERE logdate NOT LIKE '____-__-__ __:__:__'")->fetchColumn();
    if ($invalidDates > 0) {
        echo "✗ Found $invalidDates records with invalid date format\n";
        echo "  (Expected format: YYYY-MM-DD HH:MM:SS)\n";
    } else {
        echo "✓ All dates have correct format\n";
    }
    echo "\n";

    // 5. Check for negative or zero kwh values
    echo "Checking for invalid kWh values...\n";
    $negativeKwh = $db->query("SELECT COUNT(*) FROM powerlogjord WHERE kwh < 0")->fetchColumn();
    $zeroKwh = $db->query("SELECT COUNT(*) FROM powerlogjord WHERE kwh = 0")->fetchColumn();

    if ($negativeKwh > 0) {
        echo "✗ Found $negativeKwh records with NEGATIVE kWh (physically impossible)\n";
    } else {
        echo "✓ No negative kWh values\n";
    }

    if ($zeroKwh > 0) {
        echo "⚠ Found $zeroKwh records with ZERO kWh (may be valid readings)\n";
    } else {
        echo "✓ No zero kWh values (all readings have consumption)\n";
    }
    echo "\n";

    // 6. Check for duplicate timestamps
    echo "Checking for duplicate timestamps...\n";
    $duplicates = $db->query("
        SELECT COUNT(*) FROM (
            SELECT logdate, COUNT(*) as cnt FROM powerlogjord
            GROUP BY logdate HAVING cnt > 1
        )
    ")->fetchColumn();

    if ($duplicates > 0) {
        echo "⚠ Found $duplicates duplicate logdate timestamps\n";
        echo "  (This is normal - the INSERT OR REPLACE strategy handles this)\n";
    } else {
        echo "✓ No duplicate timestamps\n";
    }
    echo "\n";

    // 7. Check date range
    echo "Checking date range...\n";
    $oldestDate = $db->query("SELECT MIN(logdate) FROM powerlogjord")->fetchColumn();
    $newestDate = $db->query("SELECT MAX(logdate) FROM powerlogjord")->fetchColumn();

    if ($oldestDate && $newestDate) {
        echo "✓ Oldest record: $oldestDate\n";
        echo "✓ Newest record: $newestDate\n";
    }
    echo "\n";

    // 8. Check for kWh monotonicity (should be increasing or stable)
    echo "Checking kWh values...\n";
    $minKwh = $db->query("SELECT MIN(kwh) FROM powerlogjord")->fetchColumn();
    $maxKwh = $db->query("SELECT MAX(kwh) FROM powerlogjord")->fetchColumn();
    $avgKwh = $db->query("SELECT AVG(kwh) FROM powerlogjord")->fetchColumn();

    echo "✓ Min kWh: " . number_format($minKwh, 2) . "\n";
    echo "✓ Max kWh: " . number_format($maxKwh, 2) . "\n";
    echo "✓ Avg kWh: " . number_format($avgKwh, 2) . "\n";
    echo "\n";

    // 9. Sample data display
    echo "Sample of latest 5 records:\n";
    $sample = $db->query("
        SELECT logdate, kwh FROM powerlogjord
        ORDER BY logdate DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sample as $record) {
        echo "  " . $record['logdate'] . " - " . number_format($record['kwh'], 2) . " kWh\n";
    }
    echo "\n";

    // 10. Final verdict
    echo "=== Data Integrity Report ===\n";
    $issues = $nullLogdate + $nullKwh + $negativeKwh;

    if ($issues === 0) {
        echo "✓ NO CRITICAL ISSUES FOUND\n";
        echo "✓ Data is clean and ready for sync\n";
        echo "\nYou can safely proceed with Phase 5 testing.\n";
    } else {
        echo "✗ CRITICAL ISSUES FOUND: $issues\n";
        echo "⚠ Data has corruption. Review above for details.\n";
        echo "\nConsider cleaning data before syncing (see cleanup options below).\n";
    }

    echo "\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Cleanup Options (if needed) ===\n";
echo "If you found issues above, here are cleanup commands:\n\n";

echo "Remove records with NULL logdate:\n";
echo "  sqlite3 /var/www/monta/data/powerlog_data.db \"DELETE FROM powerlogjord WHERE logdate IS NULL;\"\n\n";

echo "Remove records with NULL kwh:\n";
echo "  sqlite3 /var/www/monta/data/powerlog_data.db \"DELETE FROM powerlogjord WHERE kwh IS NULL;\"\n\n";

echo "Remove records with negative kwh:\n";
echo "  sqlite3 /var/www/monta/data/powerlog_data.db \"DELETE FROM powerlogjord WHERE kwh < 0;\"\n\n";

echo "Remove all duplicate logdate entries (keep only latest kWh value):\n";
echo "  sqlite3 /var/www/monta/data/powerlog_data.db \"\n";
echo "    DELETE FROM powerlogjord WHERE rowid NOT IN (\n";
echo "      SELECT MAX(rowid) FROM powerlogjord GROUP BY logdate\n";
echo "    );\n";
echo "  \"\n\n";

echo "Check the database after cleanup:\n";
echo "  php check_data_integrity.php\n";

// ─── charges.db ──────────────────────────────────────────────────────────────
echo "\n\n=== charges.db Integrity Check ===\n\n";

try {
    $cdb = DatabaseManager::getChargesDb();

    // ── charges table ─────────────────────────────────────────────────────────
    echo "Checking charges table...\n";
    $chargesTotal = $cdb->query("SELECT COUNT(*) FROM charges")->fetchColumn();
    echo "✓ Total records: $chargesTotal\n";

    $nullStart = $cdb->query("SELECT COUNT(*) FROM charges WHERE startedAt IS NULL")->fetchColumn();
    $nullStop  = $cdb->query("SELECT COUNT(*) FROM charges WHERE stoppedAt  IS NULL")->fetchColumn();
    $nullKwh   = $cdb->query("SELECT COUNT(*) FROM charges WHERE consumedKwh IS NULL OR consumedKwh < 0")->fetchColumn();
    $orphaned  = $cdb->query("SELECT COUNT(*) FROM charges c LEFT JOIN vehicles v ON c.vehicleId = v.id WHERE v.id IS NULL")->fetchColumn();

    echo ($nullStart  > 0 ? "⚠ $nullStart records with NULL startedAt\n"  : "✓ No NULL startedAt\n");
    echo ($nullStop   > 0 ? "⚠ $nullStop records with NULL stoppedAt\n"   : "✓ No NULL stoppedAt\n");
    echo ($nullKwh    > 0 ? "⚠ $nullKwh records with NULL/negative consumedKwh\n" : "✓ All consumedKwh valid\n");
    echo ($orphaned   > 0 ? "⚠ $orphaned charges reference non-existent vehicleId\n" : "✓ All charge vehicleIds exist\n");

    $oldestCharge = $cdb->query("SELECT MIN(startedAt) FROM charges WHERE startedAt IS NOT NULL")->fetchColumn();
    $newestCharge = $cdb->query("SELECT MAX(stoppedAt) FROM charges WHERE stoppedAt IS NOT NULL")->fetchColumn();
    echo "✓ Date range: $oldestCharge → $newestCharge\n\n";

    // ── ext_charges table ─────────────────────────────────────────────────────
    echo "Checking ext_charges table...\n";
    $extTotal = $cdb->query("SELECT COUNT(*) FROM ext_charges")->fetchColumn();
    echo "✓ Total records: $extTotal\n";

    $extNullDate = $cdb->query("SELECT COUNT(*) FROM ext_charges WHERE datetime IS NULL OR datetime = ''")->fetchColumn();
    $extNullKwh  = $cdb->query("SELECT COUNT(*) FROM ext_charges WHERE kwh IS NULL OR kwh <= 0")->fetchColumn();
    $extOrphaned = $cdb->query("SELECT COUNT(*) FROM ext_charges e LEFT JOIN vehicles v ON e.vehicleId = v.id WHERE v.id IS NULL")->fetchColumn();
    $extOrphanProv = $cdb->query("SELECT COUNT(*) FROM ext_charges e LEFT JOIN providers p ON e.providerId = p.id WHERE p.id IS NULL")->fetchColumn();

    echo ($extNullDate    > 0 ? "⚠ $extNullDate ext_charges with NULL/empty datetime\n"  : "✓ All datetimes set\n");
    echo ($extNullKwh     > 0 ? "⚠ $extNullKwh ext_charges with NULL/zero kwh\n"          : "✓ All kwh valid\n");
    echo ($extOrphaned    > 0 ? "⚠ $extOrphaned ext_charges reference non-existent vehicleId\n" : "✓ All ext_charge vehicleIds exist\n");
    echo ($extOrphanProv  > 0 ? "⚠ $extOrphanProv ext_charges reference non-existent providerId\n" : "✓ All ext_charge providerIds exist\n");
    echo "\n";

    // ── vehicles table ────────────────────────────────────────────────────────
    echo "Checking vehicles table...\n";
    $vTotal = $cdb->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
    $vEmpty = $cdb->query("SELECT COUNT(*) FROM vehicles WHERE vehicleName IS NULL OR vehicleName = ''")->fetchColumn();
    echo "✓ Total vehicles: $vTotal\n";
    echo ($vEmpty > 0 ? "⚠ $vEmpty vehicles with empty name\n" : "✓ All vehicles have names\n");
    echo "\n";

    // ── providers table ───────────────────────────────────────────────────────
    echo "Checking providers table...\n";
    $pTotal = $cdb->query("SELECT COUNT(*) FROM providers")->fetchColumn();
    $pEmpty = $cdb->query("SELECT COUNT(*) FROM providers WHERE providerName IS NULL OR providerName = ''")->fetchColumn();
    echo "✓ Total providers: $pTotal\n";
    echo ($pEmpty > 0 ? "⚠ $pEmpty providers with empty name\n" : "✓ All providers have names\n");
    echo "\n";

    // ── vehicle_charges table ─────────────────────────────────────────────────
    echo "Checking vehicle_charges table...\n";
    $vcTotal  = $cdb->query("SELECT COUNT(*) FROM vehicle_charges")->fetchColumn();
    $vcNullTs = $cdb->query("SELECT COUNT(*) FROM vehicle_charges WHERE cablePluggedInAt IS NULL OR cablePluggedInAt = ''")->fetchColumn();
    $vcOrphan = $cdb->query("SELECT COUNT(*) FROM vehicle_charges vc LEFT JOIN vehicles v ON vc.vehicleId = v.id WHERE v.id IS NULL")->fetchColumn();
    echo "✓ Total records: $vcTotal\n";
    echo ($vcNullTs > 0 ? "⚠ $vcNullTs vehicle_charges with NULL timestamp\n" : "✓ All timestamps set\n");
    echo ($vcOrphan > 0 ? "⚠ $vcOrphan vehicle_charges reference non-existent vehicleId\n" : "✓ All vehicleIds exist\n");
    echo "\n";

    $chargesIssues = $nullStart + $nullStop + $nullKwh + $orphaned
                   + $extNullDate + $extNullKwh + $extOrphaned + $extOrphanProv
                   + $vEmpty + $pEmpty + $vcNullTs + $vcOrphan;
    echo "=== charges.db Report: " . ($chargesIssues === 0 ? "✓ NO ISSUES" : "⚠ $chargesIssues issue(s) found") . " ===\n";

} catch (Exception $e) {
    echo "✗ charges.db Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
