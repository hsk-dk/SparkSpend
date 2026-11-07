<?php
/**
 * Daily energy per device from Home Assistant (table device_energy in the powerlog database).
 *
 * validate() is a pure function (unit-tested: php dev/test_device_energy.php): everything from the
 * outside is untrusted, so keys, dates and numbers are checked before anything is stored.
 */
final class DeviceEnergy
{
    /** Devices the app knows by name. Any other key matching KEY_PATTERN is stored too (label = key). */
    public const LABELS = [
        'varme_skur' => 'Varme - Skur',
        'fryser'     => 'Fryser',
        'tv_stue'    => 'TV-stuen',
        'kontor_it'  => 'Kontor IT',
    ];

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    public const MAX_DAYS    = 400;          // one request may backfill about a year
    public const MAX_KWH_DAY = 500.0;        // a household circuit does not use more per day

    /**
     * Accepts one day {date, devices:{key:kWh}} or several {days:[{date, devices}]}.
     *
     * @return array{ok:bool, error:?string, rows:array<int,array{day:string,device:string,kwh:float}>}
     */
    public static function validate(array $input, string $today): array
    {
        $fail = static fn(string $m) => ['ok' => false, 'error' => $m, 'rows' => []];

        $days = isset($input['days']) ? $input['days'] : [$input];
        if (!is_array($days) || !array_is_list($days) || $days === []) return $fail('days must be a non-empty list');
        if (count($days) > self::MAX_DAYS) return $fail('at most ' . self::MAX_DAYS . ' days per request');

        $rows = [];
        foreach ($days as $i => $d) {
            if (!is_array($d)) return $fail("day #$i: not an object");
            $date = $d['date'] ?? null;
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                return $fail("day #$i: date must be YYYY-MM-DD");
            }
            if ($date > $today) return $fail("day #$i: date is in the future");
            if ($date < '2015-01-01') return $fail("day #$i: date is too old");
            $devices = $d['devices'] ?? null;
            if (!is_array($devices) || $devices === [] || array_is_list($devices)) {
                return $fail("day #$i: devices must be an object {key: kWh}");
            }
            foreach ($devices as $key => $kwh) {
                $key = (string) $key;
                if (!preg_match(self::KEY_PATTERN, $key)) return $fail("day #$i: bad device key");
                if (!is_int($kwh) && !is_float($kwh) && !(is_string($kwh) && is_numeric($kwh))) {
                    return $fail("day #$i: $key is not a number");
                }
                $kwh = (float) $kwh;
                if (!is_finite($kwh) || $kwh < 0 || $kwh > self::MAX_KWH_DAY) {
                    return $fail("day #$i: $key is out of range (0 - " . self::MAX_KWH_DAY . ' kWh)');
                }
                $rows[] = ['day' => $date, 'device' => $key, 'kwh' => round($kwh, 3)];
            }
        }
        return ['ok' => true, 'error' => null, 'rows' => $rows];
    }

    /** Upsert; returns the number of rows written. */
    public static function store(PDO $db, array $rows): int
    {
        $stmt = $db->prepare("INSERT INTO device_energy (day, device, kwh, updated_at) VALUES (?, ?, ?, ?)
                              ON CONFLICT(day, device) DO UPDATE SET kwh = excluded.kwh, updated_at = excluded.updated_at");
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $db->beginTransaction();
        try {
            foreach ($rows as $r) $stmt->execute([$r['day'], $r['device'], $r['kwh'], $now]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return count($rows);
    }

    /** @return array<string,float> 'Y-m-d' => kWh for one device (empty when the table or data is missing) */
    public static function daily(PDO $db, string $device, string $from, string $to): array
    {
        try {
            $stmt = $db->prepare("SELECT day, kwh FROM device_energy WHERE device = ? AND day BETWEEN ? AND ? ORDER BY day");
            $stmt->execute([$device, $from, $to]);
            return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (\Throwable $e) {
            return [];   // table not migrated yet
        }
    }

    /** @return array<string,float> 'Y-m' => kWh */
    public static function monthly(PDO $db, string $device, string $from, string $to): array
    {
        $out = [];
        foreach (self::daily($db, $device, $from, $to) as $day => $kwh) {
            $m = substr($day, 0, 7);
            $out[$m] = round(($out[$m] ?? 0.0) + $kwh, 3);
        }
        return $out;
    }
}
