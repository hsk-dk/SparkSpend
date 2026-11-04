<?php
/**
 * Server-side electricity cost estimates (elvarme model), assembled from the
 * Energi Data Service prices (ElspotPrices), the hourly consumption
 * (HourlyConsumption) and the pure model in ElvarmeCost.
 *
 * Each method mirrors one place that used to compute this in JavaScript, so the
 * browser only displays amounts.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ElvarmeCost.php';
require_once __DIR__ . '/ElspotPrices.php';
require_once __DIR__ . '/HourlyConsumption.php';

final class CostEstimates
{
    /** Mean of the kr_kwh field over records (0 when there are none). */
    private static function mean(array $records): float
    {
        $n = count($records);
        if ($n === 0) return 0.0;
        $sum = 0.0;
        foreach ($records as $r) $sum += (float) $r['kr_kwh'];
        return $sum / $n;
    }

    /**
     * Mean kr/kWh per calendar year over the daily records, ignoring days without a
     * price (rate <= 0).
     *
     * @return array<string,float> 'YYYY' => mean
     */
    private static function meanByYear(array $dailyRecords): array
    {
        $sum = []; $cnt = [];
        foreach ($dailyRecords as $r) {
            $rate = (float) $r['kr_kwh'];
            if (!($rate > 0)) continue;
            $yr = substr($r['date'], 0, 4);
            $sum[$yr] = ($sum[$yr] ?? 0.0) + $rate;
            $cnt[$yr] = ($cnt[$yr] ?? 0) + 1;
        }
        $avg = [];
        foreach ($sum as $yr => $total) $avg[$yr] = $cnt[$yr] > 0 ? $total / $cnt[$yr] : 0.0;
        return $avg;
    }

    /**
     * Annual report: estimated heat pump and whole-house cost per year, priced at that
     * year's mean HIGH/LOW rates. The monthly allowance is scaled to a full year.
     * Rest = house - heat pump - EV (never negative); EV is priced at the LOW rate.
     *
     * @param array $years [{year, ev:{kwh}, heatpump:{kwh}, hus:{kwh}|null}, ...]
     * @return array{costs: array<string,array{vp:float,hus:?float}>, complete: bool}
     *         costs[year] only for years that have prices; complete=false when prices were
     *         unavailable (rate-limited) so the result must not be cached.
     */
    public static function annual(array $years, string $today): array
    {
        $area = Config::elspotArea();
        $gln  = Config::elspotGln();
        $nums = array_values(array_filter(array_map(fn($y) => (int) $y['year'], $years)));
        if ($area === '' || $gln === '' || !$nums) return ['costs' => [], 'complete' => true];

        $start = min($nums) . '-01-01';
        $high = ElspotPrices::compute($start, $today, $area, $gln, 'high');
        $low  = ElspotPrices::compute($start, $today, $area, $gln, 'low');
        $highAvg = self::meanByYear($high['daily']);
        $lowAvg  = self::meanByYear($low['daily']);
        $highKwhPerYear = Config::elvarmeHighKwhPerMonth() * 12;

        $costs = [];
        foreach ($years as $y) {
            $key = (string) $y['year'];
            $hr = $highAvg[$key] ?? 0.0;
            $lr = $lowAvg[$key]  ?? 0.0;
            if ($hr <= 0 && $lr <= 0) continue;
            $vpKwh = (float) ($y['heatpump']['kwh'] ?? 0);
            $evKwh = (float) ($y['ev']['kwh'] ?? 0);
            $restKwh = $y['hus'] !== null ? max(0.0, (float) ($y['hus']['kwh'] ?? 0) - $vpKwh - $evKwh) : 0.0;
            $c = ElvarmeCost::cost([
                'vpKwh' => $vpKwh, 'restKwh' => $restKwh, 'evKwh' => $evKwh,
                'highRate' => $hr, 'lowRate' => $lr, 'highKwhPerMonth' => $highKwhPerYear,
            ]);
            $costs[$key] = ['vp' => $c['vpCost'], 'hus' => $y['hus'] !== null ? $c['total'] : null];
        }
        return ['costs' => $costs, 'complete' => $high['complete'] && $low['complete']];
    }

    /**
     * Monthly bill: heat pump and "rest" cost per month at that month's mean HIGH/LOW
     * rate (mean over the days that HAVE a price: days without one, such as the
     * unpublished future days of the current month, must not drag the rate down).
     * Rest = house - EV - heat pump (never negative); EV cost comes from Monta.
     *
     * @param string[] $months  'YYYY-MM'
     * @param array<string,array{kwh:float}> $hp, $hus   kWh per month
     * @param array<string,array{kwh:float,cost:float}> $ev
     * @return array{costs: array<string,?array{vp:float,rest:float}>, complete: bool}|null
     *         costs[month] is null for a month without prices.
     *         null when prices are not configured or not available at all
     */
    public static function monthly(array $months, array $hp, array $hus, array $ev, string $start, string $end): ?array
    {
        $area = Config::elspotArea();
        $gln  = Config::elspotGln();
        if ($area === '' || $gln === '') return null;

        $high = ElspotPrices::compute($start, $end, $area, $gln, 'high');
        $low  = ElspotPrices::compute($start, $end, $area, $gln, 'low');
        if (!$high['daily'] && !$low['daily']) return null;
        $highAvg = self::meanByMonth($high['daily']);
        $lowAvg  = self::meanByMonth($low['daily']);
        $highKwhPerMonth = Config::elvarmeHighKwhPerMonth();

        $costs = [];
        foreach ($months as $m) {
            $hpKwh  = (float) ($hp[$m]['kwh']  ?? 0);
            $evKwh  = (float) ($ev[$m]['kwh']  ?? 0);
            $husKwh = (float) ($hus[$m]['kwh'] ?? 0);
            // No price for the month (EDS unavailable / rate-limited): unknown, NOT 0 kr.
            if (!isset($highAvg[$m]) && !isset($lowAvg[$m])) { $costs[$m] = null; continue; }
            $residualKwh = $husKwh > 0 ? $husKwh - $evKwh - $hpKwh : 0.0;
            $c = ElvarmeCost::cost([
                'vpKwh' => $hpKwh, 'restKwh' => max(0.0, $residualKwh),
                'highRate' => $highAvg[$m] ?? 0.0, 'lowRate' => $lowAvg[$m] ?? 0.0,
                'highKwhPerMonth' => $highKwhPerMonth,
            ]);
            $costs[$m] = ['vp' => $c['vpCost'], 'rest' => $c['restCost']];
        }
        return ['costs' => $costs, 'complete' => $high['complete'] && $low['complete']];
    }

    /** Mean kr/kWh per 'YYYY-MM' over the daily records that have a price (> 0). */
    private static function meanByMonth(array $dailyRecords): array
    {
        $sum = []; $cnt = [];
        foreach ($dailyRecords as $r) {
            $rate = (float) $r['kr_kwh'];
            if (!($rate > 0)) continue;
            $m = substr($r['date'], 0, 7);
            $sum[$m] = ($sum[$m] ?? 0.0) + $rate;
            $cnt[$m] = ($cnt[$m] ?? 0) + 1;
        }
        $avg = [];
        foreach ($sum as $m => $t) $avg[$m] = $t / $cnt[$m];
        return $avg;
    }

    /**
     * House view (Hus > Forbrug): estimated cost per row and in total, for the three
     * modes that show kr.
     *
     *  daily   rows have day,hp,ev,rest   -> that day's rate; the monthly HIGH allowance is
     *                                       prorated to the day so it is not applied 30 times
     *  monthly rows have month,hp,ev,rest -> that month's mean rate, full monthly allowance
     *  compare rows have year,month,kwh   -> whole-house kWh only (no VP/EV split): the
     *                                       elvarme allowance is applied to the house total
     *
     * @return array|null null when prices are not configured/available
     */
    public static function house(string $mode, array $rows, string $start, string $end): ?array
    {
        $area = Config::elspotArea();
        $gln  = Config::elspotGln();
        if ($area === '' || $gln === '' || $end < $start) return null;

        $high = ElspotPrices::compute($start, $end, $area, $gln, 'high');
        $low  = ElspotPrices::compute($start, $end, $area, $gln, 'low');
        if (!$high['daily']) return null;

        $highDay = []; $lowDay = [];
        foreach ($high['daily'] as $r) $highDay[$r['date']] = (float) $r['kr_kwh'];
        foreach ($low['daily']  as $r) $lowDay[$r['date']]  = (float) $r['kr_kwh'];
        $highMonth = self::meanByMonth($high['daily']);
        $lowMonth  = self::meanByMonth($low['daily']);
        $highKwhMonth = Config::elvarmeHighKwhPerMonth();

        if ($mode === 'compare') {
            $years = [];
            foreach ($rows as $d) {
                $m = $d['year'] . '-' . str_pad((string) ($d['month'] ?? ''), 2, '0', STR_PAD_LEFT);
                $c = ElvarmeCost::cost([
                    'restKwh' => (float) $d['kwh'],
                    'highRate' => $highMonth[$m] ?? 0.0, 'lowRate' => $lowMonth[$m] ?? 0.0,
                    'highKwhPerMonth' => $highKwhMonth,
                ]);
                $years[$d['year']] = ($years[$d['year']] ?? 0.0) + $c['total'];
            }
            return ['years' => $years, 'components' => $low['components'], 'complete' => $high['complete'] && $low['complete']];
        }

        $out = []; $tot = ['vp' => 0.0, 'rest' => 0.0, 'ev' => 0.0, 'total' => 0.0];
        foreach ($rows as $d) {
            if ($mode === 'daily') {
                $key = $d['day'];
                $highRate = $highDay[$key] ?? 0.0;
                $lowRate  = $lowDay[$key]  ?? 0.0;
                $dim = (int) date('t', strtotime($key));
                $allowance = $highKwhMonth / ($dim ?: 30);
            } else {
                $key = $d['month'];
                $highRate = $highMonth[$key] ?? 0.0;
                $lowRate  = $lowMonth[$key]  ?? 0.0;
                $allowance = $highKwhMonth;
            }
            $c = ElvarmeCost::cost([
                'vpKwh' => (float) ($d['hp'] ?? 0), 'restKwh' => (float) ($d['rest'] ?? 0), 'evKwh' => (float) ($d['ev'] ?? 0),
                'highRate' => $highRate, 'lowRate' => $lowRate, 'highKwhPerMonth' => $allowance,
            ]);
            $out[$key] = ['vp' => $c['vpCost'], 'rest' => $c['restCost'], 'ev' => $c['evCost'], 'total' => $c['total']];
            $tot['vp'] += $c['vpCost']; $tot['rest'] += $c['restCost']; $tot['ev'] += $c['evCost']; $tot['total'] += $c['total'];
        }
        return ['rows' => $out, 'totals' => $tot, 'components' => $low['components'], 'complete' => $high['complete'] && $low['complete']];
    }

    /**
     * Dashboard cost block: month-to-date and projected cost of the heat pump and the
     * rest of the house, plus the total electricity bill estimate.
     *
     * HIGH/LOW rates are the plain means of the month's hourly prices; when hourly
     * consumption is available the heat pump and rest are priced with consumption-
     * WEIGHTED rates instead (the hours that used the electricity count in proportion
     * to the kWh used).
     *
     * @param array $in hpMtdKwh, restMtdKwh, hpProjKwh, restProjKwh (kWh; as displayed by the cards),
     *                  evMtdCost, evProjCost (kr, Monta), dayOfMonth, daysInMonth, perDay (subscription kr/day),
     *                  start, end (YYYY-MM-DD, month start .. today)
     * @return array|null null when prices are not configured/available
     */
    public static function dashboard(PDO $chargesDb, PDO $powerlogDb, array $in): ?array
    {
        $area = Config::elspotArea();
        $gln  = Config::elspotGln();
        if ($area === '' || $gln === '') return null;

        $high = ElspotPrices::compute($in['start'], $in['end'], $area, $gln, 'high');
        $low  = ElspotPrices::compute($in['start'], $in['end'], $area, $gln, 'low');

        $highRate     = self::mean($high['hourly']);
        $lowRate      = self::mean($low['hourly']);
        $highKwhMonth = Config::elvarmeHighKwhPerMonth();
        if ($highRate <= 0 && $lowRate <= 0) return null;

        $weighted = null;
        try {
            $cons = HourlyConsumption::series($chargesDb, $powerlogDb, $in['start'], $in['end']);
            $weighted = ElvarmeCost::weightedRates($cons, $high['hourly'], $low['hourly']);
        } catch (\Throwable $e) {
            error_log('CostEstimates::dashboard hourly consumption: ' . $e->getMessage());
        }
        $rateOpts = [];
        if ($weighted !== null) {
            if ($weighted['vp'] !== null)   $rateOpts['vpRates']   = $weighted['vp'];
            if ($weighted['rest'] !== null) $rateOpts['restRates'] = $weighted['rest'];
        }
        $common = ['highRate' => $highRate, 'lowRate' => $lowRate, 'highKwhPerMonth' => $highKwhMonth] + $rateOpts;

        $mtd  = ElvarmeCost::cost(['vpKwh' => $in['hpMtdKwh'],  'restKwh' => $in['restMtdKwh']]  + $common);
        // The projected kWh are priced at the month-to-date (weighted) rates: the remaining
        // days are assumed to follow the same usage pattern and price level. EV is not part
        // of it - its cost is Monta's, and Rest already excludes EV home charging.
        $proj = ElvarmeCost::cost(['vpKwh' => $in['hpProjKwh'], 'restKwh' => $in['restProjKwh']] + $common);

        $hpCostProj   = (int) round($proj['vpCost']);
        $restCostProj = (int) round($proj['restCost']);
        $perDay       = (float) $in['perDay'];
        $subMtd       = $perDay * $in['dayOfMonth'];
        $subProj      = $perDay * $in['daysInMonth'];

        return [
            'basis'              => $weighted !== null ? 'weighted' : 'average',
            'low_rate'           => $lowRate,
            'high_kwh_month'     => $highKwhMonth,
            'hp_mtd'             => $mtd['vpCost'],
            'rest_mtd'           => $mtd['restCost'],
            'hp_proj'            => $hpCostProj,
            'rest_proj'          => $restCostProj,
            'ev_proj'            => $in['evProjCost'],
            'subscription_mtd'   => $subMtd,
            'subscription_proj'  => $subProj,
            'per_day'            => $perDay,
            'total_proj'         => (int) round($in['evProjCost'] + $hpCostProj + $restCostProj + $subProj),
            'total_mtd'          => (int) round($in['evMtdCost'] + $mtd['vpCost'] + $mtd['restCost'] + $subMtd),
        ];
    }
}
