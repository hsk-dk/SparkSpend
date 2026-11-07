<?php
/**
 * The Monta sync as a function of (database, API, log): fetch what is new, store it, record the outcome in
 * sync_log. cron/update_monta_data.php only wires in the real database, the real client and CronJob.
 */
final class MontaSync
{
    public const DEFAULT_FROM = '2022-05-22T09:30:03Z';

    /**
     * Where the next fetch starts: one second after the newest stoppedAt we have. CAST(... AS TEXT) so a row
     * stored as BLOB (older PDO behaviour; BLOB sorts above TEXT in SQLite) does not hide the real maximum.
     */
    public static function fromDate(PDO $db): string
    {
        $max = $db->query("SELECT MAX(CAST(stoppedAt AS TEXT)) FROM charges")->fetchColumn();
        $ts  = ($max !== false && $max !== null && $max !== '') ? strtotime((string) $max) : false;
        return $ts === false ? self::DEFAULT_FROM : gmdate('Y-m-d\TH:i:s\Z', $ts + 1);
    }

    /**
     * @param callable(string,string=):void $log
     * @return int 0 ok, 2 rate-limited / network (try again next run); anything worse throws
     */
    public static function run(PDO $db, MontaApi $api, callable $log, ?int $now = null, ?callable $afterImport = null): int
    {
        $now  ??= time();
        $from   = self::fromDate($db);
        $to     = gmdate('Y-m-d\TH:i:s\Z', $now + 3600);

        $token   = $api->token();
        $charges = $api->charges($token, $from, $to);
        if ($charges === null) {
            SyncLog::error($db, 'monta', 'Rate-limited or network error - retrying next run', date('Y-m-d H:i:s', $now));
            return 2;
        }

        $importer = new ChargeImporter($db, $log);
        $importer->import($charges);
        $count = count($charges);

        $stamp = date('Y-m-d H:i:s', $now);
        SyncLog::success($db, 'monta', $count, $stamp, $stamp);
        if ($count > 0) {
            $log("Synced {$count} charges from {$from}: {$importer->inserted} new, {$importer->updated} updated"
                . ($importer->skipped ? ", {$importer->skipped} skipped" : '') . '.');
            if ($afterImport) $afterImport();
        }
        return 0;
    }
}
