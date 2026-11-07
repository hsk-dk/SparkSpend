<?php
/**
 * Heat pump meter: copies the hourly readings from the MySQL logger to powerlogjord (SQLite).
 * Cron: every 5 minutes. All logic is in includes/MeterSync.php (tested), the lock/log/exit code in CronJob.
 *
 * Usage: php cron/sync_heatpump_data.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';

exit(MeterSync::job('heatpump'));
