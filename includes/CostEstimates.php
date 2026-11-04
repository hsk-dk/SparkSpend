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
