<?php
/**
 * Validation / normalisation of a charger event posted by Home Assistant
 * (pure function, unit-tested: php dev/test_charger_events.php).
 *
 * Everything from the outside is untrusted: unknown keys are dropped, numbers are
 * range-checked, strings are length- and character-limited, and the cars snapshot
 * is rebuilt from a whitelist before it is stored as JSON.
 */
final class ChargerEvents {

    public const EVENTS = ['plugged', 'charge_start', 'charge_end', 'unplugged'];

    private const MAX_FUTURE_SEC = 600;               // clock skew tolerance
    private const MAX_AGE_SEC    = 30 * 86400;        // a queued retry may arrive late, not months late

    /**
     * @param array $input   decoded JSON body
     * @param int   $now     unix time (injected for tests)
     * @return array{ok:bool, error:?string, row:?array}
     */
    public static function validate(array $input, int $now): array {
        $fail = static fn(string $m) => ['ok' => false, 'error' => $m, 'row' => null];

        $event = $input['event'] ?? null;
        if (!is_string($event) || !in_array($event, self::EVENTS, true)) {
            return $fail('event must be one of: ' . implode(', ', self::EVENTS));
        }

        $eventAt = self::toUtcZ($input['eventAt'] ?? null);
        if ($eventAt === null) {
            return $fail('eventAt must be an ISO 8601 timestamp');
        }
        $ts = strtotime($eventAt);
        if ($ts > $now + self::MAX_FUTURE_SEC)  return $fail('eventAt is in the future');
        if ($ts < $now - self::MAX_AGE_SEC)     return $fail('eventAt is too old');

        $status = self::cleanString($input['status'] ?? null, 40);

        $sessionEnergy  = self::cleanNumber($input['sessionEnergy']  ?? null, 0, 1000);
        $lifetimeEnergy = self::cleanNumber($input['lifetimeEnergy'] ?? null, 0, 10_000_000);
        $power          = self::cleanNumber($input['power']          ?? null, -1, 100);
        foreach (['sessionEnergy' => $sessionEnergy, 'lifetimeEnergy' => $lifetimeEnergy, 'power' => $power] as $name => $v) {
            if ($v === false) return $fail("$name is out of range or not a number");
        }

        $cars = null;
        if (isset($input['cars'])) {
            if (!is_array($input['cars'])) return $fail('cars must be an object keyed by vehicleId');
            $cars = self::cleanCars($input['cars']);
            if ($cars === false) return $fail('cars: keys must be positive vehicleIds (max 20 cars)');
        }

        return ['ok' => true, 'error' => null, 'row' => [
            'eventAt'        => $eventAt,
            'event'          => $event,
            'chargerStatus'  => $status,
            'sessionEnergy'  => $sessionEnergy,
            'lifetimeEnergy' => $lifetimeEnergy,
            'power'          => $power,
            'cars'           => $cars === null || $cars === [] ? null : json_encode($cars, JSON_UNESCAPED_UNICODE),
        ]];
    }

    /** ISO 8601 (any offset) -> 'Y-m-d\TH:i:s\Z' in UTC, or null if it isn't a timestamp. */
    public static function toUtcZ($v): ?string {
        if (!is_string($v) || $v === '' || strlen($v) > 40) return null;
        // Require an explicit date AND time; strtotime('tomorrow') and friends must not pass.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $v)) return null;
        $ts = strtotime($v);
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** Number in [min,max] -> float; null/''/'unknown'/'unavailable' -> null; anything else -> false. */
    private static function cleanNumber($v, float $min, float $max) {
        if ($v === null || $v === '' || (is_string($v) && in_array(strtolower($v), ['unknown', 'unavailable', 'none', 'null'], true))) return null;
        if (is_bool($v) || !is_numeric($v)) return false;
        $f = (float) $v;
        return ($f < $min || $f > $max) ? false : $f;
    }

    private static function cleanString($v, int $max): ?string {
        if (!is_string($v)) return null;
        $v = strtolower(trim($v));
        if ($v === '' || strlen($v) > $max || !preg_match('/^[a-z0-9_ .:-]+$/', $v)) return null;
        return $v;
    }

    /** @return array<string,array>|false */
    private static function cleanCars(array $cars) {
        if (count($cars) > 20) return false;
        $out = [];
        foreach ($cars as $id => $c) {
            if (!preg_match('/^[1-9]\d{0,8}$/', (string) $id) || !is_array($c)) return false;
            $soc  = self::cleanNumber($c['soc']      ?? null, 0, 100);
            $odo  = self::cleanNumber($c['odometer'] ?? null, 0, 2_000_000);
            $car  = [
                'soc'      => $soc === false ? null : $soc,
                'odometer' => $odo === false ? null : $odo,
                'cable'    => self::cleanString($c['cable'] ?? null, 20),
                'carTs'    => self::toUtcZ($c['carTs'] ?? null),
            ];
            $out[(string) $id] = $car;
        }
        return $out;
    }
}
