<?php
/**
 * Data freshness: how old is the newest data from each source, and is that acceptable?
 *
 * A sync job can "succeed" and still bring nothing (the source stopped, the price cache was never
 * warmed). The cron logs only show it to someone who reads them. This turns it into one verdict that
 * both the health endpoint (Uptime Kuma) and the dashboard can use.
 *
 * evaluate() is pure and unit-tested (php dev/test_freshness.php); collect() reads the databases and
 * the price cache directory and hands the facts to evaluate().
 */
final class Freshness
{
    public const STATUS_OK      = 'ok';
    public const STATUS_STALE   = 'stale';     // newest data is older than allowed
    public const STATUS_ERROR   = 'error';     // the last run reported an error
    public const STATUS_UNKNOWN = 'unknown';   // never seen any data

    /** Maximum acceptable age per check, in seconds. Cron runs every 5/15/30 min; allow a few misses. */
    public const MAX_AGE = [
        'monta'      => 45 * 60,          // */15
        'heatpump'   => 30 * 60,          // */5
        'hus'        => 30 * 60,          // */5
        'meter_data' => 3 * 3600,         // newest hourly meter reading (stops if the source stops)
        'heatpump_data' => 3 * 3600,
        'prices'     => 3 * 3600,         // warm_prices every 30 min
        'device_energy' => 2 * 86400 + 3600,   // Home Assistant sends once per day
    ];

    /**
     * @param array<int,array{id:string,label:string,last:?int,max_age:int,error?:?string,optional?:bool}> $checks
     *        last = unix time of the newest data/run, null = none. optional = does not count towards the
     *        overall verdict (reported only).
     * @return array{status:string,checks:array<int,array>}  status: ok | degraded
     */
    public static function evaluate(array $checks, int $now): array
    {
        $out = []; $bad = false;
        foreach ($checks as $c) {
            $age = $c['last'] === null ? null : max(0, $now - $c['last']);
            if (!empty($c['error']))                          $st = self::STATUS_ERROR;
            elseif ($age === null)                            $st = self::STATUS_UNKNOWN;
            elseif ($age > $c['max_age'])                     $st = self::STATUS_STALE;
            else                                              $st = self::STATUS_OK;
            if ($st !== self::STATUS_OK && empty($c['optional'])) $bad = true;
            $out[] = [
                'id'      => $c['id'],
                'label'   => $c['label'],
                'status'  => $st,
                'age_sec' => $age,
                'max_age_sec' => $c['max_age'],
                'last'    => $c['last'] === null ? null : date('c', $c['last']),
                'error'   => $c['error'] ?? null,
                'optional' => !empty($c['optional']),
            ];
        }
        return ['status' => $bad ? 'degraded' : 'ok', 'checks' => $out];
    }

    /** Reads the facts. Never throws: a source that cannot be read counts as an error for that check. */
    public static function collect(PDO $chargesDb, PDO $powerlogDb, string $cacheDir, int $now): array
    {
        $checks = [];

        $syncRow = static function (PDO $db, string $source): ?array {
            try {
                $s = $db->prepare("SELECT updated_at, error_message FROM sync_log WHERE source = ?");
                $s->execute([$source]);
                return $s->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) { return null; }
        };
        $ts = static fn(?string $v): ?int => ($v !== null && $v !== '' && ($t = strtotime($v)) !== false) ? $t : null;

        foreach ([
            ['monta',    'El-bil (Monta)', $chargesDb,  'monta'],
            ['heatpump', 'Jordvarme',      $powerlogDb, 'heatpump'],
            ['hus',      'Hus',            $powerlogDb, 'housepowerlog'],
        ] as [$id, $label, $db, $source]) {
            $row = $syncRow($db, $source);
            $checks[] = ['id' => $id, 'label' => $label . ' - synkronisering', 'last' => $ts($row['updated_at'] ?? null),
                         'max_age' => self::MAX_AGE[$id], 'error' => $row['error_message'] ?? null];
        }

        $newest = static function (PDO $db, string $sql) use ($ts): ?int {
            try { return $ts((string) $db->query($sql)->fetchColumn()); } catch (\Throwable $e) { return null; }
        };
        $checks[] = ['id' => 'meter_data', 'label' => 'Husets måler - nyeste aflæsning',
                     'last' => $newest($powerlogDb, "SELECT MAX(logdate) FROM powerloghus"), 'max_age' => self::MAX_AGE['meter_data']];
        $checks[] = ['id' => 'heatpump_data', 'label' => 'Jordvarmens måler - nyeste aflæsning',
                     'last' => $newest($powerlogDb, "SELECT MAX(logdate) FROM powerlogjord"), 'max_age' => self::MAX_AGE['heatpump_data']];

        $checks[] = ['id' => 'prices', 'label' => 'Elpriser (Energi Data Service)',
                     'last' => self::newestPriceFile($cacheDir), 'max_age' => self::MAX_AGE['prices']];

        // Optional: only meaningful once Home Assistant has started sending it.
        $de = $newest($powerlogDb, "SELECT MAX(updated_at) FROM device_energy");
        if ($de !== null) {
            $checks[] = ['id' => 'device_energy', 'label' => 'Enhedsenergi fra Home Assistant', 'last' => $de,
                         'max_age' => self::MAX_AGE['device_energy'], 'optional' => true];
        }
        return self::evaluate($checks, $now);
    }

    /** mtime of the newest Energi Data Service cache file (the backoff marker is not a price file). */
    public static function newestPriceFile(string $cacheDir): ?int
    {
        $newest = null;
        foreach (glob($cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_eds_*.dat') ?: [] as $f) {
            if (str_ends_with($f, 'eds_backoff.dat')) continue;
            $m = @filemtime($f);
            if ($m !== false && ($newest === null || $m > $newest)) $newest = $m;
        }
        return $newest;
    }
}
