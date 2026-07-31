<?php
/**
 * SparkSpend Database Migration CLI
 *
 * Runs pending migrations on both databases, or shows status.
 *
 * Usage:
 *   php migrate.php            # Run all pending migrations
 *   php migrate.php --status   # Show migration status without running
 */

require __DIR__ . '/includes/configuration.php';
require __DIR__ . '/includes/DatabaseManager.php';
require __DIR__ . '/includes/MigrationRunner.php';

$showStatus = in_array('--status', $argv ?? [], true);

$databases = [
    'charges' => [
        'label' => 'Charges DB (charging_data.db)',
        'db'    => DatabaseManager::getChargesDb(),
        'dir'   => __DIR__ . '/migrations/charges',
    ],
    'powerlog' => [
        'label' => 'Powerlog DB (powerlog_data.db)',
        'db'    => DatabaseManager::getPowerlogDb(),
        'dir'   => __DIR__ . '/migrations/powerlog',
    ],
];

foreach ($databases as $key => $config) {
    echo "=== {$config['label']} ===\n";

    if ($showStatus) {
        $status = MigrationRunner::status($config['db'], $config['dir']);
        if (empty($status)) {
            echo "  No migration files found in {$config['dir']}\n";
        } else {
            foreach ($status as $m) {
                $icon = $m['status'] === 'applied' ? '✓' : '○';
                $time = $m['applied_at'] ? " ({$m['applied_at']})" : '';
                echo "  {$icon} {$m['filename']} — {$m['description']}{$time}\n";
            }
        }
    } else {
        try {
            $applied = MigrationRunner::run($config['db'], $config['dir']);
            if (empty($applied)) {
                echo "  No pending migrations.\n";
            } else {
                foreach ($applied as $filename) {
                    echo "  ✓ Applied: {$filename}\n";
                }
            }
        } catch (\Throwable $e) {
            echo "  ✗ ERROR: {$e->getMessage()}\n";
            exit(1);
        }
    }

    echo "\n";
}

echo "Done.\n";
