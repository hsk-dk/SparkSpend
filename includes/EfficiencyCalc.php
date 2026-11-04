<?php
/**
 * Consumption per km (pure functions, unit-tested: php dev/test_efficiency.php).
 *
 * Method: between two plug-in readings of the odometer, the car drove
 * (odometer difference) km, and the energy charged in that interval (charges that
 * ended after the first reading, up to and including the second) refilled it.
 * Km and kWh are then SUMMED over a period before dividing.
 *
 * What changed from the old per-interval filter (kWh > 0 and km >= 20):
 *  - Short intervals and intervals without a charge are no longer thrown away.
 *    Discarding them removed the km but not the energy that later refilled them,
 *    and if the car is plugged in daily after short trips almost nothing was left.
 *    Summing over a period lets those cancel out.
 *  - A period is made of the intervals that END in it, so a drive that started
 *    before the period is not lost at the boundary.
 *  - Every figure carries a confidence level.
 *
 * Known limit of this step (no battery level yet): the energy stored in the battery
 * at the start and end of a period is not accounted for, so a period can be off by
 * up to one battery's worth of energy. That matters little over hundreds of kWh and
 * a lot over a few: hence the confidence level. Step 2 (SoC) removes the limit.
 */
final class EfficiencyCalc {

    /** kWh charged in a period below which the unaccounted battery content dominates. */
    public const KWH_LOW  = 100.0;
    public const KWH_MID  = 300.0;
    public const KM_LOW   = 100.0;

    /**
     * Drop single odometer readings that do not fit between their neighbours.
     *
     * A sensor that is briefly unavailable can report 0 (or a wild value) once. Left in,
     * it is not just one bad interval: the NEXT interval is measured from the bad value
     * and gets tens of thousands of km. A reading is rejected when the readings around
     * it are in order (previous <= next) and it falls outside them. A real reset or a
     * replaced car looks different: several low readings in a row, where the neighbours
     * are NOT in order, so those are kept.
     *
     * @param array<int,array{ts:int,odometer:float}> $readings sorted by ts
     * @return array{readings: array<int,array>, rejected: int}
     */
    public static function cleanReadings(array $readings): array {
        $good = []; $rejected = 0; $n = count($readings);
        for ($i = 0; $i < $n; $i++) {
            $r = $readings[$i];
            if ($good !== [] && $i + 1 < $n) {
                $p = $good[count($good) - 1]['odometer'];
                $q = $readings[$i + 1]['odometer'];
                if ($p <= $q && ($r['odometer'] < $p || $r['odometer'] > $q)) { $rejected++; continue; }
            }
            $good[] = $r;
        }
        return ['readings' => $good, 'rejected' => $rejected];
    }

    /**
     * Intervals of ONE vehicle.
     *
     * @param array<int,array{ts:int,odometer:float}>        $readings  odometer at plug-in
     * @param array<int,array{ts:int,kwh:float,cost:?float}> $charges   charge ended at ts
     * @return array{intervals: array<int,array>, dropped: int}
     *   interval: from, to (unix), km, kwh, cost, unpricedKwh, charges (count)
     *   dropped:  readings rejected as outliers + intervals where the odometer went
     *             backwards (a real reset)
     */
    public static function intervals(array $readings, array $charges): array {
        usort($readings, static fn($a, $b) => $a['ts'] <=> $b['ts']);
        usort($charges,  static fn($a, $b) => $a['ts'] <=> $b['ts']);
        $cleaned  = self::cleanReadings($readings);
        $readings = $cleaned['readings'];

        $out = []; $dropped = $cleaned['rejected']; $j = 0; $m = count($charges);
        for ($i = 1, $n = count($readings); $i < $n; $i++) {
            $from = $readings[$i - 1]['ts'];
            $to   = $readings[$i]['ts'];
            $km   = $readings[$i]['odometer'] - $readings[$i - 1]['odometer'];

            while ($j < $m && $charges[$j]['ts'] <= $from) $j++;       // before this interval
            $kwh = 0.0; $cost = 0.0; $unpriced = 0.0; $count = 0;
            while ($j < $m && $charges[$j]['ts'] <= $to) {              // (from, to]
                $kwh += $charges[$j]['kwh'];
                if ($charges[$j]['cost'] === null) $unpriced += $charges[$j]['kwh'];
                else                                $cost     += $charges[$j]['cost'];
                $count++; $j++;
            }

            if ($km < 0) { $dropped++; continue; }                     // odometer went backwards
            $out[] = ['from' => $from, 'to' => $to, 'km' => $km, 'kwh' => $kwh, 'cost' => $cost,
                      'unpricedKwh' => $unpriced, 'charges' => $count];
        }
        return ['intervals' => $out, 'dropped' => $dropped];
    }

    /** Sum a list of intervals into the metrics shown to the user. */
    public static function metrics(array $intervals): array {
        $km = $kwh = $cost = $unpriced = 0.0; $n = 0;
        foreach ($intervals as $iv) { $km += $iv['km']; $kwh += $iv['kwh']; $cost += $iv['cost']; $unpriced += $iv['unpricedKwh']; $n++; }

        // Charges without a price count 0 kr; estimate them at the average price of the priced ones
        // (same rule as the dashboard's kr/kWh), and say so.
        $pricedKwh = $kwh - $unpriced;
        $estimated = $unpriced > 0.001 && $pricedKwh > 0.001;
        $costTotal = $estimated ? $cost / $pricedKwh * $kwh : $cost;

        $conf = self::confidence($km, $kwh);
        return [
            'intervals'      => $n,
            'km'             => round($km, 1),
            'kwh'            => round($kwh, 1),
            'cost'           => round($costTotal, 2),
            'kwh_per_100km'  => ($km > 0 && $kwh > 0) ? round($kwh / $km * 100, 1) : null,
            'km_per_kwh'     => ($km > 0 && $kwh > 0) ? round($km / $kwh, 2) : null,
            'kr_per_km'      => ($km > 0 && $costTotal > 0) ? round($costTotal / $km, 2) : null,
            'kr_estimated'   => $estimated,
            'confidence'     => $conf['level'],
            'confidence_why' => $conf['why'],
        ];
    }

    /** @return array{level:string, why:string} level: lav | middel | høj */
    public static function confidence(float $km, float $kwh): array {
        if ($km <= 0 || $kwh <= 0) return ['level' => 'lav', 'why' => 'ingen kørte km eller ingen ladning i perioden'];
        if ($kwh < self::KWH_LOW)  return ['level' => 'lav',    'why' => 'under ' . (int) self::KWH_LOW . ' kWh ladet: batteriets ladeniveau ved start og slut kan fylde mere end selve forbruget'];
        if ($km  < self::KM_LOW)   return ['level' => 'lav',    'why' => 'under ' . (int) self::KM_LOW . ' km kørt'];
        if ($kwh < self::KWH_MID)  return ['level' => 'middel', 'why' => 'under ' . (int) self::KWH_MID . ' kWh ladet'];
        return ['level' => 'høj', 'why' => 'mindst ' . (int) self::KWH_MID . ' kWh ladet og ' . (int) self::KM_LOW . ' km kørt'];
    }

    /** Intervals that END in [fromTs, toTs]. */
    public static function endingBetween(array $intervals, int $fromTs, int $toTs): array {
        return array_values(array_filter($intervals, static fn($iv) => $iv['to'] >= $fromTs && $iv['to'] <= $toTs));
    }

    /** Intervals ending in the last $days days before $now. */
    public static function rolling(array $intervals, int $now, int $days): array {
        return self::endingBetween($intervals, $now - $days * 86400 + 1, $now);
    }

    /** @return array<string,array> 'YYYY-MM' (local month of the interval's end) => metrics, oldest first */
    public static function byMonth(array $intervals, DateTimeZone $tz): array {
        $buckets = [];
        foreach ($intervals as $iv) {
            $key = (new DateTimeImmutable('@' . $iv['to']))->setTimezone($tz)->format('Y-m');
            $buckets[$key][] = $iv;
        }
        ksort($buckets);
        return array_map([self::class, 'metrics'], $buckets);
    }

    /**
     * Cumulative km/kWh and kr/km at the end of every interval (the old chart's shape).
     * @return array<int,array{timestamp:int,totalKm:float,totalKwh:float,totalKmPerKwh:?float,totalKrPerKm:?float}>
     */
    public static function cumulative(array $intervals): array {
        $km = $kwh = $cost = 0.0; $out = [];
        foreach ($intervals as $iv) {
            $km += $iv['km']; $kwh += $iv['kwh']; $cost += $iv['cost'];
            if ($km <= 0 || $kwh <= 0) continue;                       // nothing meaningful to plot yet
            $out[] = [
                'timestamp'     => $iv['to'],
                'totalKm'       => round($km, 1),
                'totalKwh'      => round($kwh, 1),
                'totalKmPerKwh' => round($km / $kwh, 2),
                'totalKrPerKm'  => $cost > 0 ? round($cost / $km, 2) : null,
            ];
        }
        return $out;
    }
}
