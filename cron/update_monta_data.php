<?php
/**
 * Monta: fetches the charges since the last stop and stores them (charges table).
 * Cron: every 15 minutes. All logic is in includes/MontaSync.php and ChargeImporter.php (tested), the
 * HTTP in MontaClient, the lock/log/exit code in CronJob.
 *
 * Exit code: 0 ok, 1 failed, 2 rate-limited / network down (the next run tries again).
 *
 * Usage: php cron/update_monta_data.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';

exit(CronJob::run('monta', 'Monta Sync', function (callable $log): int {
    if (Config::montaClientId() === '' || Config::montaClientSecret() === '') {
        throw new RuntimeException('MONTA_CLIENT_ID / MONTA_CLIENT_SECRET are not configured in .env');
    }
    $api = new MontaClient(Config::montaClientId(), Config::montaClientSecret(), Config::montaAuthEndpoint(), Config::montaDataEndpoint(), $log);
    return MontaSync::run(DatabaseManager::getChargesDb(), $api, $log, null, fn() => CacheHelper::invalidate(['charges', 'vehicles']));
}, function (\Throwable $e): void {
    SyncLog::error(DatabaseManager::getChargesDb(), 'monta', $e->getMessage());
}));
