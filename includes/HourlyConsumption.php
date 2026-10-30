<?php
/**
 * Hourly consumption series (pure functions, unit-tested:
 *   php dev/test_hourly_consumption.php).
 *
 * The meters (powerlogjord / powerloghus) store one cumulative reading per hour.
 * Consumption per hour is the difference between consecutive readings. This is
 * the hourly counterpart of PowerlogRepository::lagDelta() (daily), and uses the
 * same rules: a reset interval (counter went down) is unknown and counts as 0.
 *
 * Keys are local wall-clock hours, 'Y-m-d H' (e.g. '2026-10-29 07'), matching the
 * {date, hour} records of the elspot-prices route.
 */
final class HourlyConsumption {

    /** A gap between two readings longer than this is not spread (outage too long to guess). */
    public const MAX_GAP_HOURS = 336;   // 14 days

    /**
     * @param array<int,array{logdate:string,kwh:float|string}> $rows  ascending by logdate
     * @return array<string,float>  'Y-m-d H' => kWh
     */
    public static function fromReadings(array $rows): array {
        $utc  = new DateTimeZone('UTC');   // wall-clock labels only; UTC avoids DST arithmetic surprises
        $out  = [];
        $prev = null;
        foreach ($rows as $r) {
            $t   = new DateTimeImmutable($r['logdate'], $utc);
            $kwh = (float) $r['kwh'];
            if ($prev !== null) {
                $delta = $kwh - $prev['kwh'];
                $gap   = max(1, (int) round(($t->getTimestamp() - $prev['ts']) / 3600));
                // After a sensor stall the first reading carries the whole gap's
                // consumption: spread it evenly over the hours it covers.
                if ($delta > 0 && $gap <= self::MAX_GAP_HOURS) {
                    $per = $delta / $gap;
                    for ($k = 0; $k < $gap; $k++) {
                        $key = $t->modify("-{$k} hours")->format('Y-m-d H');
                        $out[$key] = ($out[$key] ?? 0.0) + $per;
                    }
                }
            }
            $prev = ['ts' => $t->getTimestamp(), 'kwh' => $kwh];
        }
        return $out;
    }

    /**
     * Spread one charging session over the local hours it overlapped,
     * proportionally to the time in each hour.
     *
     * @param string  $startedAt  ISO timestamp with zone (Monta: ...Z)
     * @param ?string $endedAt    same; null / not after start -> all kWh in the start hour
     * @return array<string,float>
     */
    public static function spreadCharge(string $startedAt, ?string $endedAt, float $kwh, string $tz): array {
        $zone = new DateTimeZone($tz);
        $s = (new DateTimeImmutable($startedAt))->getTimestamp();
        $e = $endedAt !== null && $endedAt !== '' ? (new DateTimeImmutable($endedAt))->getTimestamp() : $s;

        $hourKey = fn(int $ts) => (new DateTimeImmutable('@' . $ts))->setTimezone($zone);
        if ($e <= $s) {
            return [$hourKey($s)->format('Y-m-d H') => $kwh];
        }

        $out = [];
        $total = $e - $s;
        $cur = $s;
        while ($cur < $e) {
            $local     = $hourKey($cur);
            $hourStart = $local->setTime((int) $local->format('H'), 0, 0)->getTimestamp();
            $segEnd    = min($e, $hourStart + 3600);
            $key       = $local->format('Y-m-d H');
            $out[$key] = ($out[$key] ?? 0.0) + $kwh * ($segEnd - $cur) / $total;
            $cur       = $segEnd;
        }
        return $out;
    }

    /**
     * "Rest" = house - heat pump - home EV charging, per hour (never negative).
     * Same definition the dashboard uses for the monthly figures.
     *
     * @param array<string,float> $house
     * @param array<string,float> $heatPump
     * @param array<string,float> $ev
     * @return array<string,float>
     */
    public static function rest(array $house, array $heatPump, array $ev): array {
        $out = [];
        foreach ($house as $key => $kwh) {
            $out[$key] = max(0.0, $kwh - ($heatPump[$key] ?? 0.0) - ($ev[$key] ?? 0.0));
        }
        return $out;
    }
}
