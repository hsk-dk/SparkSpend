<?php
/**
 * Copies hourly meter readings from the MySQL logger to the local SQLite tables. The heat-pump job and the
 * house job were two copies of the same 300 lines; this is the one implementation, with the MySQL side
 * behind a fetch function so the rest can be tested without a MySQL server.
 *
 *   MeterSync::SOURCES['hus'] / ['heatpump']  what differs between them
 *   MeterSync::run()                          fetch -> store -> sync_log (-> caches), testable
 *   MeterSync::mysqlFetcher()                 the MySQL query (hourly buckets of the cumulative kWh)
 */
final class MeterSync
{
    /** Readings above this are Wh stored by a logger bug (about 1000x too big) - divide by 1000. */
    public const WH_THRESHOLD = 100000;

    /**
     * table: local SQLite table (logdate, kwh) | source: sync_log row | mysqlConfig: Config key of the MySQL table
     * normaliseWh: only the house meter has the Wh logger bug | label: prefix in cron.log | cron: lock name
     */
    public const SOURCES = [
        'heatpump' => ['table' => 'powerlogjord', 'source' => 'heatpump',      'mysqlConfig' => 'mysqlHeatpumpTable',   'mysqlDefault' => 'powerlog',     'normaliseWh' => false, 'label' => 'Heat Pump Sync',   'cron' => 'heatpump'],
        'hus'      => ['table' => 'powerloghus',  'source' => 'housepowerlog', 'mysqlConfig' => 'mysqlHousePowerTable', 'mysqlDefault' => 'powerloghus',  'normaliseWh' => true,  'label' => 'House Power Sync', 'cron' => 'hus'],
    ];

    /** Records per MySQL page: a first sync is big, an incremental one small. */
    public const PAGE_FIRST = 50000;
    public const PAGE_INCREMENTAL = 1000;
    /** Stop collecting after this many (the rest comes with the next run). */
    public const LIMIT_FIRST = 100000;
    public const LIMIT_INCREMENTAL = 10000;
    /** SQLite commit every N rows, so the write lock is not held for long. */
    public const COMMIT_EVERY = 1000;

    /**
     * The whole cron job for one meter ('heatpump' | 'hus'): lock, connect, copy, record the outcome.
     * cron/sync_heatpump_data.php and cron/sync_housepowerlog_data.php are one line each around this.
     */
    public static function job(string $key): int
    {
        $def = self::SOURCES[$key];
        return CronJob::run($def['cron'], $def['label'], function (callable $log) use ($def): int {
            $mysql  = MySQLManager::getHeatpumpDb();          // both meter tables live in the same MySQL database
            $sqlite = DatabaseManager::getPowerlogDb();
            $fetch  = self::mysqlFetcher($mysql, (string) Config::get($def['mysqlConfig'], $def['mysqlDefault']), $def['normaliseWh']);
            self::run($sqlite, $def, $fetch, $log, fn() => CacheHelper::invalidateMeterCaches());
            return 0;
        }, function (\Throwable $e) use ($def): void {
            // Only if SQLite can be reached; otherwise the error is in cron.log (CronJob) and /health.php goes stale.
            SyncLog::error(DatabaseManager::getPowerlogDb(), $def['source'], $e->getMessage());
        });
    }

    /**
     * @param callable(?string,int,int):array $fetch  fn(?string $after, int $pageSize, int $offset): array of ['logdate','kwh']
     * @param callable(string,string=):void   $log
     * @return int records stored
     */
    public static function run(PDO $sqlite, array $def, callable $fetch, callable $log, ?callable $afterStore = null): int
    {
        $after   = SyncLog::watermark($sqlite, $def['source']);
        $records = self::collect($fetch, $after, $log);
        $count   = count($records);

        $stored = 0; $max = null;
        if ($count > 0) {
            $stored = self::store($sqlite, $def['table'], $records, $log);
            foreach ($records as $r) {
                $ld = $r['logdate'] ?? null;
                if ($ld && ($max === null || $ld > $max)) $max = $ld;
            }
            $log("Stored {$stored} of {$count} hourly records (up to {$max}).");
            if ($afterStore) $afterStore();
        }
        // Always: the run itself is information ("the job runs"), also when nothing was new.
        SyncLog::success($sqlite, $def['source'], $count, $max);
        return $stored;
    }

    /** Pages through the source until it runs dry or the per-run limit is reached. */
    public static function collect(callable $fetch, ?string $after, callable $log): array
    {
        $page   = $after ? self::PAGE_INCREMENTAL : self::PAGE_FIRST;
        $limit  = $after ? self::LIMIT_INCREMENTAL : self::LIMIT_FIRST;
        $offset = 0; $all = [];
        while (true) {
            $batch = $fetch($after, $page, $offset);
            if (!$batch) break;
            $all = array_merge($all, $batch);
            $offset += $page;
            if (count($all) >= $limit) {
                $log('Batch limit reached at ' . count($all) . ' records - the rest follows on the next run.');
                break;
            }
        }
        return $all;
    }

    /** INSERT OR REPLACE, committed in chunks; a row that cannot be stored is skipped with a warning. */
    public static function store(PDO $db, string $table, array $records, callable $log): int
    {
        if (!in_array($table, ['powerlogjord', 'powerloghus'], true)) throw new InvalidArgumentException("unknown table $table");
        $stmt = $db->prepare("INSERT OR REPLACE INTO {$table} (logdate, kwh) VALUES (?, ?)");
        $stored = 0;
        $db->beginTransaction();
        try {
            foreach ($records as $r) {
                $logdate = $r['logdate'] ?? null; $kwh = $r['kwh'] ?? null;
                if ($logdate === null || $kwh === null) continue;
                try { $stmt->execute([$logdate, (float) $kwh]); $stored++; }
                catch (PDOException $e) { $log("Could not store logdate={$logdate}: " . $e->getMessage(), 'WARN'); }
                if ($stored > 0 && $stored % self::COMMIT_EVERY === 0) { $db->commit(); $db->beginTransaction(); }
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $stored;
    }

    /** The unit fix as PHP (the SQL below does the same on the MySQL side): Wh readings become kWh. */
    public static function normaliseKwh(float $kwh): float
    {
        return $kwh > self::WH_THRESHOLD ? round($kwh / 1000.0, 2) : $kwh;
    }

    /**
     * The MySQL side: hourly buckets of the cumulative counter (MAX per hour), after the watermark.
     * The table name comes from .env, never from a request; it is whitelisted to identifier characters.
     */
    public static function mysqlFetcher(PDO $mysql, string $table, bool $normaliseWh): callable
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new InvalidArgumentException('bad MySQL table name');
        $kwh = $normaliseWh
            ? "CASE WHEN MAX(kwh) > " . self::WH_THRESHOLD . " THEN ROUND(MAX(kwh) / 1000.0, 2) ELSE MAX(kwh) END"
            : "MAX(kwh)";
        return static function (?string $after, int $page, int $offset) use ($mysql, $table, $kwh): array {
            $where = $after ? 'WHERE logdate > ?' : '';
            $sql = "SELECT DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00') AS logdate, {$kwh} AS kwh
                    FROM `{$table}` {$where}
                    GROUP BY DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00')
                    ORDER BY logdate ASC
                    LIMIT " . (int) $page . ' OFFSET ' . (int) $offset;
            $stmt = $mysql->prepare($sql);
            try { $stmt->execute($after ? [$after] : []); }
            catch (PDOException $e) { throw new RuntimeException('MySQL query failed: ' . $e->getMessage(), 0, $e); }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
    }
}
