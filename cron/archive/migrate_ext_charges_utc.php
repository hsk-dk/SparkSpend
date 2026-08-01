<?php
/**
 * Migration: fix ext_charges.datetime timezone offset
 *
 * PROBLEM
 * -------
 * createExCharge.php and updateExtCharge.php stored datetimes using:
 *   (new DateTime($localInput))->format('Y-m-d\TH:i:00\Z')
 * This appended a literal Z without converting to UTC, so a charge
 * entered at 23:30 CET was stored as "23:30Z" (= 00:30 next day CET).
 * Callers that use strtotime() interpret the Z as UTC, and date() with
 * the local timezone then returns the wrong date for charges near midnight.
 *
 * FIX
 * ---
 * For each row: strip the Z, parse as Europe/Copenhagen (local), convert
 * to UTC, write back. The offset depends on DST at the time of the charge:
 *   CET  (UTC+1): Nov – Mar  → subtract 1 hour
 *   CEST (UTC+2): Mar – Oct  → subtract 2 hours
 * PHP's DateTimeZone handles this automatically.
 *
 * USAGE
 * -----
 *   php cron/migrate_ext_charges_utc.php           # dry-run (no changes)
 *   php cron/migrate_ext_charges_utc.php --commit  # apply changes
 */

require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';

$commit = in_array('--commit', $argv ?? [], true);
$tz     = new DateTimeZone('Europe/Copenhagen');
$utc    = new DateTimeZone('UTC');

$db   = DatabaseManager::getChargesDb();
$rows = $db->query("SELECT id, datetime FROM ext_charges ORDER BY datetime")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Ingen ext_charges fundet.\n";
    exit(0);
}

echo ($commit ? "COMMIT-TILSTAND" : "DRY-RUN (ingen ændringer)") . " — {$rows[0]['datetime']} → ...\n";
echo str_repeat('-', 72) . "\n";
printf("%-6s  %-22s  %-22s  %s\n", "ID", "Gemt (lokal+Z)", "Rettet (UTC+Z)", "Diff");
echo str_repeat('-', 72) . "\n";

$changed = 0;
$stmt    = $commit ? $db->prepare("UPDATE ext_charges SET datetime = ? WHERE id = ?") : null;

foreach ($rows as $row) {
    $stored = $row['datetime']; // e.g. "2026-03-16T23:30:00Z"

    // Strip trailing Z and parse as local time
    $localStr = rtrim($stored, 'Z');
    $dt       = new DateTime($localStr, $tz);      // interpret as CET/CEST
    $dt->setTimezone($utc);                         // convert to UTC
    $corrected = $dt->format('Y-m-d\TH:i:s\Z');   // format back

    // Work out the offset that was applied (for display)
    $orig  = new DateTime($stored);       // PHP reads Z as UTC → gives the "wrong" UTC ts
    $diff  = $dt->getTimestamp() - $orig->getTimestamp(); // should be -3600 or -7200
    $diffH = $diff / 3600;

    $marker = ($stored !== $corrected) ? ($diff != 0 ? sprintf('%+dh', $diffH) : '=') : '=';

    printf("%-6s  %-22s  %-22s  %s\n", $row['id'], $stored, $corrected, $marker);

    if ($stored !== $corrected) {
        $changed++;
        if ($commit) {
            $stmt->execute([$corrected, $row['id']]);
        }
    }
}

echo str_repeat('-', 72) . "\n";
echo "{$changed} af " . count($rows) . " rækker " . ($commit ? "opdateret." : "vil blive opdateret.") . "\n";

if (!$commit && $changed > 0) {
    echo "\nKør med --commit for at anvende ændringerne:\n";
    echo "  php cron/migrate_ext_charges_utc.php --commit\n";
}
