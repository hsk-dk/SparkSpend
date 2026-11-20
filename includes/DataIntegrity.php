<?php
/**
 * Data integrity checks on the two SQLite databases: the kinds of damage this app has actually had.
 *
 *  - Type damage: a number stored as TEXT or BLOB (charges.vehicleId was stored as BLOB for some rows; SQLite
 *    does not convert a BLOB, so `WHERE vehicleId = 2` silently missed them and the charges had no car).
 *    Checked generically for every INTEGER / REAL column of every table, so a new column is covered at once.
 *  - Orphans: a charge, external charge or odometer reading that points at a car or provider that does not exist.
 *
 * check() returns doctor-style results; php doctor.php runs it, and dev/test_data_integrity.php runs it on
 * every kind of write the app does.
 */
final class DataIntegrity
{
    /** relation: [table, column, parent table, parent column, label] - for the charges database */
    private const RELATIONS = [
        ['charges',          'vehicleId',  'vehicles',  'id', 'ladninger med ukendt bil'],
        ['ext_charges',      'vehicleId',  'vehicles',  'id', 'eksterne ladninger med ukendt bil'],
        ['ext_charges',      'providerId', 'providers', 'id', 'eksterne ladninger med ukendt udbyder'],
        ['vehicle_charges',  'vehicleId',  'vehicles',  'id', 'kilometeraflæsninger med ukendt bil'],
    ];

    /**
     * @return array<int,array{level:string,name:string,detail:string}>
     */
    public static function check(PDO $db, string $label): array
    {
        $out = [];
        $bad = self::typeDamage($db);
        foreach ($bad as $b) {
            $out[] = ['level' => 'fail', 'name' => "{$label}: {$b['table']}.{$b['column']} har {$b['count']} værdi(er) som {$b['type']}",
                      'detail' => "kolonnen er {$b['declared']}; tal gemt som tekst/blob matches ikke af sammenligninger (se migrering charges/006)"];
        }
        $orphans = 0;
        foreach (self::orphans($db) as $o) {
            $orphans++;
            $out[] = ['level' => 'warn', 'name' => "{$label}: {$o['count']} {$o['label']}", 'detail' => "{$o['table']}.{$o['column']} peger på {$o['parent']}, som ikke findes"];
        }
        if (!$bad && !$orphans) $out[] = ['level' => 'ok', 'name' => "{$label}: datatyper og relationer er i orden", 'detail' => ''];
        return $out;
    }

    /** How far back the content checks look. Older rows are history: a stray one from last year should not keep the doctor yellow. */
    public const CONTENT_DAYS = 90;

    /**
     * Rows that are well-formed but wrong in a way that distorts a figure, in the last CONTENT_DAYS days (charges database).
     * The type and orphan checks above find damage to the data; these find implausible data:
     *  - a stop before the start, or a negative / absurd kWh or price (the session figures and kr/kWh);
     *  - a session still open after two days (it counts up to "now" until Monta closes it);
     *  - a charge with kWh but no price (counts 0 kr, so the month's price is understated);
     *  - an odometer reading of 0, or one lower than the one before (kr/km and km/kWh; EfficiencyCalc drops the outliers, but
     *    it is worth knowing that the car or the importer sent one).
     * Each finding is a warning with a count and an example id. Tables that are missing are skipped.
     *
     * @return array<int,array{level:string,name:string,detail:string}>
     */
    public static function content(PDO $db, string $label, ?int $now = null, int $days = self::CONTENT_DAYS): array
    {
        $now  = $now ?? time();
        $from = gmdate('Y-m-d H:i:s', $now - $days * 86400);
        $open = gmdate('Y-m-d H:i:s', $now - 2 * 86400);
        $have = array_flip(self::tables($db));
        $out  = [];
        $warn = static function (string $what, int $n, string $example, string $why) use (&$out, $label): void {
            if ($n > 0) $out[] = ['level' => 'warn', 'name' => "{$label}: {$n} {$what}", 'detail' => "eksempel: {$example}. {$why}"];
        };
        // count + an example id in one query: the first column is the count, the second an example
        $q = static function (string $sql, array $args) use ($db): array {
            $s = $db->prepare($sql); $s->execute($args);
            $r = $s->fetch(PDO::FETCH_NUM);
            return [(int) ($r[0] ?? 0), (string) ($r[1] ?? '')];
        };

        if (isset($have['charges'])) {
            $in = "datetime(startedAt) >= ?";
            [$n, $ex] = $q("SELECT COUNT(*), MIN(id) FROM charges WHERE $in AND stoppedAt IS NOT NULL AND datetime(stoppedAt) < datetime(startedAt) AND consumedKwh > 0", [$from]);
            $warn('ladninger, hvor stop ligger før start', $n, "id $ex", 'varighed og kW bliver forkerte; ret i Monta-importen eller i rækken');
            [$n, $ex] = $q("SELECT COUNT(*), MIN(id) FROM charges WHERE $in AND (consumedKwh < 0 OR consumedKwh > 150 OR cost < 0)", [$from]);
            $warn('ladninger med negative eller urealistiske kWh/pris (kWh over 150 eller under 0, pris under 0)', $n, "id $ex", 'tallene tælles med i måneden');
            [$n, $ex] = $q("SELECT COUNT(*), MIN(id) FROM charges WHERE stoppedAt IS NULL AND datetime(startedAt) >= ? AND datetime(startedAt) < ?", [$from, $open]);
            $warn('ladninger uden stoptid, startet for mere end 2 dage siden', $n, "id $ex", 'Monta har ikke lukket dem; de tælles op til sidste opdatering');
            [$n, $ex] = $q("SELECT COUNT(*), MIN(id) FROM charges WHERE $in AND stoppedAt IS NOT NULL AND consumedKwh > 0 AND cost IS NULL", [$from]);
            $warn('ladninger med kWh men uden pris', $n, "id $ex", 'de tæller 0 kr, så månedens pris er undervurderet (kortet viser en stjerne)');
        }
        if (isset($have['ext_charges'])) {
            [$n, $ex] = $q("SELECT COUNT(*), MIN(id) FROM ext_charges WHERE datetime(datetime) >= ? AND (kwh <= 0 OR pris < 0)", [$from]);
            $warn('eksterne ladninger med kWh på 0 eller negativ pris', $n, "id $ex", 'ret eller slet rækken');
        }
        if (isset($have['vehicle_charges'])) {
            [$n, $ex] = $q("SELECT COUNT(*), MIN(id) FROM vehicle_charges WHERE datetime(cablePluggedInAt) >= ? AND (odometer IS NULL OR odometer = 0)", [$from]);
            $warn('kilometeraflæsninger på 0', $n, "id $ex", 'kr/km og km/kWh springer dem over, men bilen eller importen har sendt en tom aflæsning');
            [$n, $ex] = $q("SELECT COUNT(*), MIN(id) FROM (
                                SELECT id, odometer AS o, cablePluggedInAt AS at,
                                       LAG(odometer) OVER (PARTITION BY vehicleId ORDER BY datetime(cablePluggedInAt)) AS p
                                FROM vehicle_charges WHERE odometer > 0
                            ) WHERE p IS NOT NULL AND p > 0 AND o < p AND datetime(at) >= ?", [$from]);
            $warn('kilometeraflæsninger lavere end den forrige', $n, "id $ex", 'en kilometerstand kan ikke gå baglæns: en forkert aflæsning eller en ny bil på samme id');
        }
        if (!$out) $out[] = ['level' => 'ok', 'name' => "{$label}: indholdet ser rigtigt ud (seneste {$days} dage)", 'detail' => ''];
        return $out;
    }

    /** @return array<int,array{table:string,column:string,declared:string,type:string,count:int}> */
    public static function typeDamage(PDO $db): array
    {
        $found = [];
        foreach (self::tables($db) as $table) {
            foreach ($db->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $declared = strtoupper((string) $col['type']);
                if (!preg_match('/INT|REAL|FLOA|DOUB|NUM|DEC/', $declared)) continue;
                $name = $col['name'];
                // Text/blob that LOOKS like a number: that is the damage (a car id '2' stored as bytes). Real text in a
                // column that is merely declared INTEGER (vehicles.vehicleName is, in production) is a schema oddity, not damage.
                $stmt = $db->query('SELECT typeof("' . $name . '") AS t, COUNT(*) AS n FROM "' . $table . '"
                                    WHERE typeof("' . $name . '") IN (\'text\', \'blob\')
                                      AND length(CAST("' . $name . '" AS TEXT)) > 0
                                      AND CAST("' . $name . '" AS TEXT) NOT GLOB \'*[^0-9.eE+-]*\'
                                    GROUP BY t');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $found[] = ['table' => $table, 'column' => $name, 'declared' => $declared, 'type' => $r['t'], 'count' => (int) $r['n']];
                }
            }
        }
        return $found;
    }

    /** @return array<int,array{table:string,column:string,parent:string,label:string,count:int}> */
    public static function orphans(PDO $db): array
    {
        $have = array_flip(self::tables($db));
        $found = [];
        foreach (self::RELATIONS as [$table, $col, $parent, $pcol, $label]) {
            if (!isset($have[$table], $have[$parent])) continue;
            // CAST so a mistyped value is still compared (type damage is reported separately)
            $n = (int) $db->query("SELECT COUNT(*) FROM \"{$table}\" t
                                   WHERE t.\"{$col}\" IS NOT NULL AND t.\"{$col}\" <> 0
                                     AND NOT EXISTS (SELECT 1 FROM \"{$parent}\" p WHERE p.\"{$pcol}\" = CAST(t.\"{$col}\" AS INTEGER))")->fetchColumn();
            if ($n > 0) $found[] = ['table' => $table, 'column' => $col, 'parent' => $parent, 'label' => $label, 'count' => $n];
        }
        return $found;
    }

    /** @return string[] user tables (not sqlite_* and not the migration bookkeeping) */
    private static function tables(PDO $db): array
    {
        return $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> '_migrations' ORDER BY name")
                  ->fetchAll(PDO::FETCH_COLUMN);
    }
}
