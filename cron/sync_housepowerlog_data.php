<?php
/**
 * House meter: copies the hourly readings from the MySQL logger to powerloghus (SQLite).
 * Readings above 100,000 are Wh stored by a logger bug and are divided by 1000 (MeterSync::WH_THRESHOLD).
 * Cron: every 5 minutes. All logic is in includes/MeterSync.php (tested), the lock/log/exit code in CronJob.
 *
 * Usage: php cron/sync_housepowerlog_data.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';

exit(MeterSync::job('hus'));
