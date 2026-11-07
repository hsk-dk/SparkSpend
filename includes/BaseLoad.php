<?php
/**
 * Base load ("basislast"): what the house draws when nothing big is running, in watts.
 *
 * Restforbrug (house - heat pump - EV) is mostly this: the fridge, freezer, router, standby, pumps
 * and anything else that runs all day. It is estimated from the hourly meter readings:
 *
 *   per day    the mean of the BASE_HOURS lowest hours of "house - heat pump" (kWh in one hour = kW),
 *              looking only at hours without any home EV charging and where the house meter is not
 *              below the heat pump meter (an artefact of unsynchronised meters). A day needs at least
 *              MIN_HOURS such hours.
 *   per month  the median of the days (robust against a few odd days), plus the lowest and highest.
 *
 * Pure functions on hourly arrays (keys 'Y-m-d H'), unit-tested: php dev/test_base_load.php.
 */
final class BaseLoad
{
    public const BASE_HOURS = 3;
    public const MIN_HOURS  = 18;

    /**
     * @param array<string,float> $house     kWh per hour
     * @param array<string,float> $heatPump  kWh per hour
     * @param array<string,float> $ev        home EV charging kWh per hour
     * @return array<string,int>  'Y-m-d' => watts
     */
    public static function daily(array $house, array $heatPump, array $ev): array
    {
        $byDay = [];
        foreach ($house as $hour => $kwh) {
            if (($ev[$hour] ?? 0.0) > 0.0) continue;                 // charging touched this hour
            $rest = $kwh - ($heatPump[$hour] ?? 0.0);
            if ($rest < 0.0) continue;                               // meters out of step
            $byDay[substr($hour, 0, 10)][] = $rest;
        }
        $out = [];
        ksort($byDay);
        foreach ($byDay as $day => $values) {
            if (count($values) < self::MIN_HOURS) continue;
            sort($values);
            $lowest = array_slice($values, 0, self::BASE_HOURS);
            $out[$day] = (int) round(array_sum($lowest) / count($lowest) * 1000);
        }
        return $out;
    }

    /**
     * @param array<string,int> $daily 'Y-m-d' => watts
     * @return array<string,array{median:int,min:int,max:int,days:int}>  'Y-m' => ...
     */
    public static function monthly(array $daily): array
    {
        $byMonth = [];
        foreach ($daily as $day => $watts) $byMonth[substr($day, 0, 7)][] = $watts;
        $out = [];
        ksort($byMonth);
        foreach ($byMonth as $month => $values) {
            sort($values);
            $n = count($values);
            $median = $n % 2 ? $values[intdiv($n, 2)] : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
            $out[$month] = ['median' => $median, 'min' => $values[0], 'max' => $values[$n - 1], 'days' => $n];
        }
        return $out;
    }

    /**
     * Months of base load for the last $months months, read from the databases.
     * @return array{months: array, latest: ?array, params: array}
     */
    public static function build(PDO $chargesDb, PDO $powerlogDb, int $months, string $today): array
    {
        $start = date('Y-m-01', strtotime($today . ' -' . ($months - 1) . ' months'));
        $p     = HourlyConsumption::parts($chargesDb, $powerlogDb, $start, $today);
        $daily = self::daily($p['house'], $p['heatPump'], $p['ev']);
        $by    = self::monthly($daily);

        $rows = [];
        foreach ($by as $month => $m) $rows[] = ['month' => $month] + $m;

        // "Now": the median of the last 14 days with data
        $recent = array_slice($daily, -14, null, true);
        $latest = null;
        if (count($recent) >= 5) {
            $v = array_values($recent); sort($v);
            $latest = ['watts' => $v[intdiv(count($v), 2)], 'days' => count($v), 'from' => array_key_first($recent), 'to' => array_key_last($recent)];
            $latest['kwh_per_month'] = (int) round($latest['watts'] * 24 * 30 / 1000);
        }
        return [
            'months' => $rows,
            'latest' => $latest,
            'params' => ['base_hours' => self::BASE_HOURS, 'min_hours' => self::MIN_HOURS, 'from' => $start, 'to' => $today],
        ];
    }
}
