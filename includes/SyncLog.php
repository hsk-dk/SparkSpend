<?php
/**
 * sync_log: what each sync job last did. One row per source:
 *
 *   last_sync_timestamp  the watermark - the newest data the job has brought in; the next run starts after it
 *   last_sync_count      records in the last run
 *   updated_at           when the job last ran (always written, so "the job runs" is visible even when
 *                        there was nothing new)
 *   error_message        set when the last run failed; cleared by the next success
 *
 * Used by the three sync jobs, read by get_sync_status.php and Freshness. An error keeps the watermark:
 * the heat-pump job used to write its error with INSERT OR REPLACE and thereby threw the watermark away,
 * so the next run started over from the very first record.
 */
final class SyncLog
{
    public static function watermark(PDO $db, string $source): ?string
    {
        try {
            $s = $db->prepare("SELECT last_sync_timestamp FROM sync_log WHERE source = ?");
            $s->execute([$source]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null || $v === '') ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;   // table not there yet: start from the beginning
        }
    }

    /** A run that finished. $newWatermark null = keep the old one (nothing new came in). */
    public static function success(PDO $db, string $source, int $count, ?string $newWatermark, ?string $now = null): void
    {
        $now ??= date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO sync_log (source, last_sync_timestamp, last_sync_count, updated_at, error_message)
                      VALUES (:s, :w, :c, :n, NULL)
                      ON CONFLICT(source) DO UPDATE SET
                          last_sync_timestamp = COALESCE(:w, last_sync_timestamp),
                          last_sync_count     = :c,
                          updated_at          = :n,
                          error_message       = NULL")
           ->execute([':s' => $source, ':w' => $newWatermark, ':c' => $count, ':n' => $now]);
    }

    /** A run that failed. Keeps the watermark and the last count. */
    public static function error(PDO $db, string $source, string $message, ?string $now = null): void
    {
        $now ??= date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO sync_log (source, last_sync_count, updated_at, error_message)
                      VALUES (:s, 0, :n, :m)
                      ON CONFLICT(source) DO UPDATE SET updated_at = :n, error_message = :m")
           ->execute([':s' => $source, ':n' => $now, ':m' => function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500)]);
    }
}
