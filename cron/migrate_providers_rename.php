<?php
/**
 * Migration: rename SQLite table 'provideres' → 'providers'
 *
 * PROBLEM
 * -------
 * The table was created with a typo: 'provideres' instead of 'providers'.
 * All PHP code has been updated to reference 'providers'. This script
 * performs the one-time database rename to match.
 *
 * USAGE
 * -----
 *   php cron/migrate_providers_rename.php           # dry-run (no changes)
 *   php cron/migrate_providers_rename.php --commit  # apply changes
 */

require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';

$commit = in_array('--commit', $argv ?? [], true);
$db     = DatabaseManager::getChargesDb();

// Check current state
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
              ->fetchAll(PDO::FETCH_COLUMN);

$hasOld = in_array('provideres', $tables);
$hasNew = in_array('providers',  $tables);

echo ($commit ? "COMMIT-TILSTAND" : "DRY-RUN (ingen ændringer)") . "\n";
echo str_repeat('-', 50) . "\n";

if (!$hasOld && $hasNew) {
    echo "Tabellen hedder allerede 'providers' — ingen handling nødvendig.\n";
    exit(0);
}

if (!$hasOld && !$hasNew) {
    echo "FEJL: Hverken 'provideres' eller 'providers' fundet.\n";
    exit(1);
}

if ($hasOld && $hasNew) {
    echo "FEJL: Begge tabeller eksisterer — manuel indgriben kræves.\n";
    exit(1);
}

// $hasOld && !$hasNew — the expected case
$count = $db->query("SELECT COUNT(*) FROM provideres")->fetchColumn();
echo "Tabel 'provideres' fundet med {$count} rækker.\n";
echo "Vil omdøbe til 'providers'.\n";

if ($commit) {
    $db->exec("ALTER TABLE provideres RENAME TO providers");
    echo "\nFærdig. Tabellen hedder nu 'providers'.\n";
} else {
    echo "\nKør med --commit for at anvende:\n";
    echo "  php cron/migrate_providers_rename.php --commit\n";
}
