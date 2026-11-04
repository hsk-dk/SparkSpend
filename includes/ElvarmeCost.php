<?php
/**
 * Danish electricity-heating cost model (server-side, pure functions).
 *
 * This is the single source of truth for the model. It is a line-for-line port of
 * `elvarmeCost` (includes/app.js) and the consumption weighting in
 * includes/pricing.js, kept identical by dev/test_elvarme_cost.php, which also
 * runs the JS versions through Node on random inputs and compares the results.
 * Once the front end consumes the amounts computed here, the JS copies can go.
 *
 * Model: the meter cannot tell purpose apart, so the first `highKwhPerMonth` kWh
 * a month of heat pump + rest consumption is taxed at the HIGH rate and the rest
 * at the LOW (elvarme) rate. EV charging always uses the LOW rate.
 */
final class ElvarmeCost
{
    /**
     * @param array $p vpKwh, restKwh, evKwh (default 0), highRate, lowRate, highKwhPerMonth,
     *                 vpRates / restRates optional ['high' => float, 'low' => float]
     * @return array{vpCost: float, restCost: float, evCost: float, total: float}
     */
    public static function cost(array $p): array
    {
        $vpKwh  = (float) ($p['vpKwh']   ?? 0);
        $restKwh = (float) ($p['restKwh'] ?? 0);
        $evKwh  = (float) ($p['evKwh']   ?? 0);
        $highRate = (float) $p['highRate'];
        $lowRate  = (float) $p['lowRate'];
        $highKwhPerMonth = (float) $p['highKwhPerMonth'];
        $vpRates   = $p['vpRates']   ?? null;
        $restRates = $p['restRates'] ?? null;

        $vp   = max(0.0, $vpKwh);
        $rest = max(0.0, $restKwh);
        $vpRest  = $vp + $rest;
        $highKwh = min($vpRest, $highKwhPerMonth);
        $lowKwh  = max(0.0, $vpRest - $highKwh);
        $evCost  = max(0.0, $evKwh) * $lowRate;

        // Optional per-component rates (e.g. consumption-weighted). The high-rate
        // allowance is shared pro rata, so each component pays high/low in the same
        // proportion. With equal rates this equals the flat model below.
        if ($vpRates !== null || $restRates !== null) {
            $flat = ['high' => $highRate, 'low' => $lowRate];
            $f    = $vpRest > 0 ? $highKwh / $vpRest : 0.0;
            $mix  = static fn(array $r): float => $f * $r['high'] + (1 - $f) * $r['low'];
            $vpCost   = $vp   * $mix($vpRates   ?? $flat);
            $restCost = $rest * $mix($restRates ?? $flat);
            return ['vpCost' => $vpCost, 'restCost' => $restCost, 'evCost' => $evCost,
                    'total' => $vpCost + $restCost + $evCost];
        }

        $vpRestCost = $highKwh * $highRate + $lowKwh * $lowRate;
        // Split the blended cost back onto VP and Rest by their kWh share.
        $vpCost   = $vpRest > 0 ? $vpRestCost * ($vp   / $vpRest) : 0.0;
        $restCost = $vpRest > 0 ? $vpRestCost * ($rest / $vpRest) : 0.0;
        return ['vpCost' => $vpCost, 'restCost' => $restCost, 'evCost' => $evCost,
                'total' => $vpRestCost + $evCost];
    }

    /**
     * Hourly price records [{date:'YYYY-MM-DD', hour:0..23, kr_kwh}] => ['YYYY-MM-DD HH' => kr_kwh].
     * On the autumn DST change the same local hour occurs twice: those are averaged.
     * Records whose price is not a finite number are skipped.
     *
     * @return array<string, float>
     */
    public static function priceByHour(array $records): array
    {
        $acc = [];
        foreach ($records as $r) {
            if (!is_array($r)) continue;
            $raw = $r['kr_kwh'] ?? null;
            if ($raw === null || $raw === '') $raw = 0;      // JS Number(null) === 0
            if (!is_numeric($raw)) continue;
            $price = (float) $raw;
            if (!is_finite($price)) continue;
            $key = ($r['date'] ?? '') . ' ' . str_pad((string) ($r['hour'] ?? ''), 2, '0', STR_PAD_LEFT);
            $acc[$key] ??= ['sum' => 0.0, 'n' => 0];
            $acc[$key]['sum'] += $price;
            $acc[$key]['n']   += 1;
        }
        $out = [];
        foreach ($acc as $k => $a) $out[$k] = $a['sum'] / $a['n'];
        return $out;
    }

    /**
     * Consumption-weighted price: sum(kWh_h * price_h) / sum(kWh_h) over the hours
     * that have BOTH a consumption figure and a price.
     *
     * @param string[]               $hours    'YYYY-MM-DD HH' keys
     * @param array<int, mixed>      $kwh      consumption per hour, aligned with $hours
     * @param array<string, float>   $priceMap from priceByHour()
     * @return float|null kr/kWh, or null when no hour has both
     */
    public static function weightedRate(array $hours, array $kwh, array $priceMap): ?float
    {
        $cost = 0.0; $total = 0.0;
        foreach ($hours as $i => $h) {
            $c = isset($kwh[$i]) && is_numeric($kwh[$i]) ? (float) $kwh[$i] : NAN;
            if (!($c > 0) || !array_key_exists($h, $priceMap)) continue;
            $cost  += $c * $priceMap[$h];
            $total += $c;
        }
        return $total > 0 ? $cost / $total : null;
    }

    /**
     * Weighted HIGH and LOW rates for the heat pump and for the rest of the house.
     *
     * @param array $consumption {hours: string[], vp: float[], rest: float[]}
     * @return array{vp: ?array{high: float, low: float}, rest: ?array{high: float, low: float}}|null
     */
    public static function weightedRates(array $consumption, array $highRecords, array $lowRecords): ?array
    {
        if (!isset($consumption['hours']) || !is_array($consumption['hours'])) return null;
        $hi = self::priceByHour($highRecords);
        $lo = self::priceByHour($lowRecords);
        $pair = static function ($kwh) use ($consumption, $hi, $lo): ?array {
            $kwh  = is_array($kwh) ? $kwh : [];
            $high = self::weightedRate($consumption['hours'], $kwh, $hi);
            $low  = self::weightedRate($consumption['hours'], $kwh, $lo);
            return $high === null || $low === null ? null : ['high' => $high, 'low' => $low];
        };
        $vp   = $pair($consumption['vp']   ?? null);
        $rest = $pair($consumption['rest'] ?? null);
        return $vp || $rest ? ['vp' => $vp, 'rest' => $rest] : null;
    }
}
