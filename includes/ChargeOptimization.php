<?php
/**
 * Charging optimisation report for the Elbil > Analyse tab: prices the home charges of the
 * last N months at the hourly electricity prices and compares them with the cheapest placement
 * (ChargeOptimizer). No HTTP and no cache here (see routes/get_charge_optimization.php).
 */
final class ChargeOptimization
{
    /**
     * @return array{payload: array, complete: bool}  complete=false when prices were incomplete
     *         (rate-limited / not yet fetched): such a payload must not be cached
     */
    public static function build(PDO $chargesDb, int $months): array
    {
        $months = max(1, min(12, $months));
        $area = Config::elspotArea();
        $gln  = Config::elspotGln();
        $today = date('Y-m-d');
        $from  = date('Y-m-01', strtotime("-" . ($months - 1) . " months"));
        $params = ['months' => $months, 'from' => $from, 'to' => $today,
                   'flex_hours' => Config::evFlexHours(), 'max_kw' => Config::evMaxKw(), 'tariff' => 'low'];

        if ($area === '' || $gln === '') {
            return ['payload' => ['available' => false, 'reason' => 'prices_not_configured', 'params' => $params], 'complete' => true];
        }

        // EV charging pays the LOW (elvarme) electricity tax rate: hourly all-in prices.
        $low = ElspotPrices::compute($from, $today, $area, $gln, 'low');
        $prices = ElvarmeCost::priceByHour($low['hourly']);
        if (!$prices) {
            return ['payload' => ['available' => false, 'reason' => 'prices_unavailable', 'params' => $params], 'complete' => false];
        }

        $result = ChargeOptimizer::analyze($chargesDb, $from, $today, $prices, [
            'flexHours' => Config::evFlexHours(),
            'maxKw'     => Config::evMaxKw(),
        ]);
        $result['params'] = $params + $result['params'];
        $result['available'] = true;
        // Most valuable charges first (the list in the UI shows the top ones).
        usort($result['charges'], static fn($a, $b) => $b['saving'] <=> $a['saving']);
        $result['charges'] = array_slice($result['charges'], 0, 25);
        return ['payload' => $result, 'complete' => $low['complete']];
    }
}
