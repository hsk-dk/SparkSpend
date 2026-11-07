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
