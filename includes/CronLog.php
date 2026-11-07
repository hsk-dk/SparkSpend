<?php
/**
 * Append-only log for the cron jobs (cron/cron.log) with size-based rotation.
 *
 * The three sync jobs each used to append to cron/cron.log with file_put_contents and nothing ever
 * trimmed it: in production it had grown to 56 MB. Now a line that would push the file past the limit
 * first rotates it:  cron.log -> cron.log.1 -> cron.log.2 ... (the oldest is deleted).
 *
 * Settings (.env, optional):
 *   LOG_MAX_BYTES   rotate when the file is larger than this (default 5 MB)
 *   LOG_KEEP        how many rotated files to keep (default 3, so at most ~4 x LOG_MAX_BYTES on disk)
 *
 * The jobs can run at the same time, so the rotation happens under an exclusive lock and the size is
 * checked again inside it: two jobs never rotate twice, and a job that wrote a moment before the
 * rename simply lands in the .1 file. Writing the log must never break a job, so everything here is
 * best-effort and silent.
 */
final class CronLog
{
    public const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;
    public const DEFAULT_KEEP      = 3;

    /** Append one line (a trailing newline is added if missing), rotating first if the file is too big. */
    public static function append(string $file, string $line, ?int $maxBytes = null, ?int $keep = null): void
    {
        if (!str_ends_with($line, "\n")) $line .= "\n";
        self::rotateIfNeeded($file, $maxBytes, $keep);
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @return bool true if the file was rotated by this call */
    public static function rotateIfNeeded(string $file, ?int $maxBytes = null, ?int $keep = null): bool
    {
        $maxBytes ??= self::limit('LOG_MAX_BYTES', self::DEFAULT_MAX_BYTES, 1024);
        $keep     ??= self::limit('LOG_KEEP', self::DEFAULT_KEEP, 1);

        clearstatcache(true, $file);
        if (!is_file($file) || (int) @filesize($file) <= $maxBytes) return false;

        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) return false;
        $rotated = false;
        if (flock($lock, LOCK_EX)) {
            clearstatcache(true, $file);
            if (is_file($file) && (int) @filesize($file) > $maxBytes) {      // another job may have rotated meanwhile
                @unlink($file . '.' . $keep);
                for ($i = $keep - 1; $i >= 1; $i--) {
                    if (is_file($file . '.' . $i)) @rename($file . '.' . $i, $file . '.' . ($i + 1));
                }
                $rotated = @rename($file, $file . '.1');
            }
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        return $rotated;
    }

    /** All files of one log, newest data last: cron.log.3, cron.log.2, cron.log.1, cron.log (only those that exist). */
    public static function files(string $file, ?int $keep = null): array
    {
        $keep ??= self::limit('LOG_KEEP', self::DEFAULT_KEEP, 1);
        $out = [];
        for ($i = $keep; $i >= 1; $i--) if (is_file($file . '.' . $i)) $out[] = $file . '.' . $i;
        if (is_file($file)) $out[] = $file;
        return $out;
    }

    private static function limit(string $envKey, int $default, int $min): int
    {
        $raw = function_exists('env') ? env($envKey) : (getenv($envKey) ?: null);
        return ($raw !== null && ctype_digit((string) $raw) && (int) $raw >= $min) ? (int) $raw : $default;
    }
}
