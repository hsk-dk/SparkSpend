<?php
/**
 * Runner for the cron jobs: one place for the lock, the log line format, the error handling and the
 * exit code - instead of every script doing its own (they had three different lock schemes, two of which
 * could be raced, one of which could not tell a slow run from a dead one, and an Error - as opposed to an
 * Exception - slipped past the catch and left no trace in sync_log).
 *
 *   exit(CronJob::run('hus', 'House Power Sync', function (callable $log): int {
 *       ... $log('Fetched 12 records'); ...
 *       return 0;                       // 0 = ok (also null), 1 = failed, 2 = soft failure (e.g. rate limit)
 *   }, onError: fn(\Throwable $e) => SyncLog::error($db, 'housepowerlog', $e->getMessage())));
 *
 * Lock: an exclusive non-blocking flock on a file in CACHE_DIR. The kernel drops it when the process dies
 * (a crashed run never blocks the next one) and a run that takes longer than expected still holds it
 * (the old "lock older than 60 s is stale" would have started a second run beside it).
 *
 * Quiet by design: a run that finds nothing logs nothing; whether the jobs run is answered by sync_log
 * and /health.php (Freshness), not by a log line every five minutes.
 */
final class CronJob
{
    /** @return string the lock file of a job ('monta', 'heatpump', 'hus', 'prices') */
    public static function lockFile(string $name): string
    {
        return Config::cacheDir() . DIRECTORY_SEPARATOR . 'sparkspend_job_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.lock';
    }

    /** Takes the lock. @return resource|null the handle (keep it until the job is done), null = already running */
    public static function acquire(string $name)
    {
        $h = @fopen(self::lockFile($name), 'c');
        if ($h === false) return null;
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); return null; }
        return $h;
    }

    public static function release($handle): void
    {
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }

    /** Is the job running right now (in any process)? */
    public static function isRunning(string $name): bool
    {
        $h = self::acquire($name);
        if ($h === null) return true;
        self::release($h);
        return false;
    }

    /**
     * @param callable(callable):(int|null) $job      receives $log(string $message, string $level = 'INFO')
     * @param callable(\Throwable):void|null $onError  called when the job throws (e.g. to write sync_log)
     * @return int exit code: 0 ok / already running, 1 failed, or whatever the job returned
     */
    public static function run(string $name, string $label, callable $job, ?callable $onError = null, ?string $logFile = null): int
    {
        $logFile ??= dirname(__DIR__) . '/cron/cron.log';
        $log = static function (string $message, string $level = 'INFO') use ($label, $logFile): void {
            CronLog::append($logFile, '[' . date('Y-m-d H:i:s') . "] [{$level}] {$label}: {$message}");
            if ($level === 'ERROR') error_log("{$label}: {$message}");
        };

        $lock = self::acquire($name);
        if ($lock === null) {
            $log('Already running - skipping this run.');
            return 0;
        }
        try {
            $code = $job($log);
            return is_int($code) ? $code : 0;
        } catch (\Throwable $e) {
            $log('Failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'ERROR');
            if ($onError !== null) {
                try { $onError($e); } catch (\Throwable $inner) { $log('Could not record the error: ' . $inner->getMessage(), 'WARN'); }
            }
            return 1;
        } finally {
            self::release($lock);
        }
    }
}
