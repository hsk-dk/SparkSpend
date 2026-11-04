<?php
/**
 * Charging optimisation: what did each home charge cost at the hourly electricity prices,
 * and what would the SAME energy have cost if it had been charged in the cheapest hours the
 * car was available for?
 *
 * Model (all assumptions are explicit parameters, shown in the UI):
 *  - Prices: the all-in hourly price (spot + tariffs + elafgift + VAT) at the LOW elvarme tax
 *    rate, which is what EV charging pays (see ElspotPrices / ElvarmeCost).
 *  - Actual: the charge's kWh spread over the hours it ran (HourlyConsumption::spreadCharge).
 *  - Window: from plug-in until the later of the charge end and plug-in + $flexHours. The car
 *    is assumed to be available that long; nothing is known about when it is really needed,
 *    so $flexHours is a setting (EV_FLEX_HOURS) and deliberately modest.
 *  - Cheapest: the same kWh placed in the cheapest priced hours of the window, at most
 *    $maxKw kWh per hour (the charger's rate, at least the charge's own average rate).
 *    The result is never worse than what was actually done.
 *
 * Pure functions plus one DB loader; no HTTP, no cache (see routes/get_charge_optimization.php).
 */
final class ChargeOptimizer
{
    /**
     * Cheapest placement of $totalKwh in the window, against the actual placement.
     *
     * @param string[]             $windowKeys  hour keys 'Y-m-d H' available for charging
     * @param array<string,float>  $actualKwh   hour key => kWh actually charged
     * @param array<string,float>  $prices      hour key => kr/kWh
     * @return array{actual: float, best: float, saving: float, best_hours: array<string,float>}|null
     *         null when an hour that was actually used has no price (cannot be valued)
     */
    public static function optimizeCharge(array $windowKeys, array $actualKwh, array $prices, float $maxKw): ?array
    {
        $total = array_sum($actualKwh);
        if ($total <= 0) return null;

        $actualCost = 0.0;
        foreach ($actualKwh as $key => $kwh) {
            if (!isset($prices[$key])) return null;
            $actualCost += $kwh * $prices[$key];
        }

        // Candidate hours: the window plus the hours actually used, that have a price.
        $candidates = [];
        foreach (array_unique(array_merge($windowKeys, array_keys($actualKwh))) as $key) {
            if (isset($prices[$key])) $candidates[$key] = $prices[$key];
        }
        // cheapest first; ties go to the earlier hour (keys sort chronologically)
        uksort($candidates, static fn($a, $b) => [$candidates[$a], $a] <=> [$candidates[$b], $b]);

        $remaining = $total;
        $bestCost = 0.0;
        $placed = [];
        foreach ($candidates as $key => $price) {
            if ($remaining <= 1e-9) break;
            $put = min($maxKw, $remaining);
            $placed[$key] = $put;
            $bestCost += $put * $price;
            $remaining -= $put;
        }
        if ($remaining > 1e-9) {                       // not enough hours at this rate: keep what was done
            return ['actual' => $actualCost, 'best' => $actualCost, 'saving' => 0.0, 'best_hours' => $actualKwh];
        }
        if ($bestCost >= $actualCost) {                // already (near) optimal
            return ['actual' => $actualCost, 'best' => $actualCost, 'saving' => 0.0, 'best_hours' => $actualKwh];
        }
        ksort($placed);
        return ['actual' => $actualCost, 'best' => $bestCost, 'saving' => $actualCost - $bestCost, 'best_hours' => $placed];
    }

    /**
     * The hour keys of [$fromTs, $toTs) in local time (DST-safe: stepping in real hours).
     *
     * @return string[]
     */
    public static function hourKeys(int $fromTs, int $toTs, string $tz): array
    {
        $zone = new DateTimeZone($tz);
        $keys = [];
        for ($t = $fromTs - ($fromTs % 3600); $t < $toTs; $t += 3600) {
            $keys[] = (new DateTimeImmutable('@' . $t))->setTimezone($zone)->format('Y-m-d H');
        }
        return array_values(array_unique($keys));
    }

    /**
     * Analyse every completed home charge that ended in [$from, $to] (YYYY-MM-DD, local).
     *
     * @param array<string,float> $prices  hour key => kr/kWh (from ElvarmeCost::priceByHour)
     * @param array{flexHours?: float, maxKw?: float, tz?: string, now?: int} $opts
     * @return array{charges: array, months: array, totals: array, params: array}
     */
    public static function analyze(PDO $db, string $from, string $to, array $prices, array $opts = []): array
    {
        $flexHours = (float) ($opts['flexHours'] ?? 12);
        $maxKw     = (float) ($opts['maxKw'] ?? 7.4);
        $tz        = (string) ($opts['tz'] ?? date_default_timezone_get());
        $now       = (int) ($opts['now'] ?? time());

        $stmt = $db->prepare("
            SELECT id, vehicleId, cablePluggedInAt, startedAt, stoppedAt, consumedKwh, cost
            FROM charges
            WHERE startedAt IS NOT NULL AND stoppedAt IS NOT NULL AND consumedKwh > 0
              AND DATE(stoppedAt) >= ? AND DATE(stoppedAt) <= ?
            ORDER BY stoppedAt
        ");
        $stmt->execute([$from, $to]);

        $charges = []; $months = []; $skipped = 0;
        $tot = ['charges' => 0, 'kwh' => 0.0, 'actual' => 0.0, 'best' => 0.0, 'saving' => 0.0, 'paid' => 0.0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $kwh   = (float) $c['consumedKwh'];
            $start = strtotime((string) $c['startedAt']);
            $stop  = strtotime((string) $c['stoppedAt']);
            if ($start === false || $stop === false || $stop <= $start || $stop > $now) { $skipped++; continue; }

            $actual = HourlyConsumption::spreadCharge($c['startedAt'], $c['stoppedAt'], $kwh, $tz);
            $plug   = $c['cablePluggedInAt'] ? strtotime((string) $c['cablePluggedInAt']) : false;
            $winFrom = ($plug !== false && $plug <= $start) ? $plug : $start;
            $winTo   = max($stop, $winFrom + (int) round($flexHours * 3600));
            $winTo   = min($winTo, $now);                     // prices of the future are not known
            $window  = self::hourKeys($winFrom, $winTo, $tz);

            $hours = max(1e-6, ($stop - $start) / 3600);
            $cap   = max($maxKw, $kwh / $hours);              // never slower than the charge itself was
            $r = self::optimizeCharge($window, $actual, $prices, $cap);
            if ($r === null) { $skipped++; continue; }

            $paid = $c['cost'] !== null ? (float) $c['cost'] : null;
            $month = (new DateTimeImmutable('@' . $stop))->setTimezone(new DateTimeZone($tz))->format('Y-m');
            $months[$month] ??= ['month' => $month, 'charges' => 0, 'kwh' => 0.0, 'actual' => 0.0, 'best' => 0.0, 'saving' => 0.0];
            $months[$month]['charges']++; $months[$month]['kwh'] += $kwh;
            $months[$month]['actual'] += $r['actual']; $months[$month]['best'] += $r['best']; $months[$month]['saving'] += $r['saving'];
            $tot['charges']++; $tot['kwh'] += $kwh; $tot['actual'] += $r['actual']; $tot['best'] += $r['best'];
            $tot['saving'] += $r['saving']; $tot['paid'] += $paid ?? 0.0;

            $charges[] = [
                'id'         => (int) $c['id'],
                'vehicleId'  => $c['vehicleId'] !== null ? (int) $c['vehicleId'] : null,
                'startedAt'  => $c['startedAt'],
                'stoppedAt'  => $c['stoppedAt'],
                'kwh'        => round($kwh, 2),
                'paid'       => $paid,
                'actual'     => round($r['actual'], 2),
                'best'       => round($r['best'], 2),
                'saving'     => round($r['saving'], 2),
                'best_hours' => self::summarizeHours($r['best_hours']),
            ];
        }

        foreach ($months as &$m) foreach (['kwh', 'actual', 'best', 'saving'] as $k) $m[$k] = round($m[$k], 2);
        unset($m);
        krsort($months);
        foreach (['kwh', 'actual', 'best', 'saving', 'paid'] as $k) $tot[$k] = round($tot[$k], 2);
        $tot['saving_pct'] = $tot['actual'] > 0 ? round($tot['saving'] / $tot['actual'] * 100, 1) : 0.0;
        $tot['skipped'] = $skipped;

        return [
            'charges' => $charges,
            'months'  => array_values($months),
            'totals'  => $tot,
            'params'  => ['flex_hours' => $flexHours, 'max_kw' => $maxKw, 'tariff' => 'low'],
        ];
    }

    /**
     * Compact description of the hours used: [{date, from, to, kwh}] with consecutive hours merged.
     *
     * @param array<string,float> $hours 'Y-m-d H' => kWh
     */
    public static function summarizeHours(array $hours): array
    {
        $out = []; $cur = null;
        foreach ($hours as $key => $kwh) {
            [$date, $h] = [substr($key, 0, 10), (int) substr($key, 11, 2)];
            if ($cur && $cur['date'] === $date && $cur['to'] === $h) { $cur['to'] = $h + 1; $cur['kwh'] += $kwh; continue; }
            if ($cur) $out[] = $cur;
            $cur = ['date' => $date, 'from' => $h, 'to' => $h + 1, 'kwh' => $kwh];
        }
        if ($cur) $out[] = $cur;
        foreach ($out as &$o) $o['kwh'] = round($o['kwh'], 2);
        return $out;
    }
}
