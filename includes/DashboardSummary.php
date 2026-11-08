<?php
/**
 * Dashboard summary: this month's EV, heat pump, house and "rest" figures with trends, projections, sparklines
 * and the electricity cost estimate. Pure data assembly: no HTTP, no cache (see routes/get_dashboard_summary.php),
 * so it can be called and tested directly.
 *
 * build() only orchestrates; the work is in
 *   DashboardPeriods  the calendar of the request (this month, the same stretch last month / year, how much of today)
 *   DashboardEv       the EV card
 *   DashboardMeters   the heat pump, house and rest cards
 * It used to be one 450-line function with thirty date variables. dev/golden/ proves the figures did not change.
 */
final class DashboardSummary
{
    /**
     * @param int|null $now the instant the dashboard describes (default: now); a test can ask for any moment
     * @return array the payload of api.php?action=dashboard
     */
    public static function build(PDO $chargesDb, PDO $powerlogDb, ?int $now = null): array
    {
        $p = DashboardPeriods::at($now ?? time());

        $ev  = DashboardEv::collect($chargesDb, $p);
        $hp  = DashboardMeters::collectHeatpump($powerlogDb, $p);
        $hus = DashboardMeters::collectHouse($powerlogDb, $p);

        $payload = [
            'ev'       => DashboardEv::payload($ev, $p),
            'heatpump' => DashboardMeters::heatpumpPayload($hp, $p),
            'hus'      => DashboardMeters::housePayload($hus, $hp['current'], floatval($ev['intMonth']['kwh']), $p),
            'rest'     => DashboardMeters::restPayload($hus, $hp, $ev, $p),
        ];
        $payload['cost'] = self::costEstimate($chargesDb, $powerlogDb, $payload, $p);

        return $payload;
    }

    /**
     * Electricity cost estimate (elvarme model, server-side). Priced from the figures exactly as the cards show
     * them (rounded as in the payload). Null if the estimate cannot be made (no prices yet, ...).
     */
    private static function costEstimate(PDO $chargesDb, PDO $powerlogDb, array $payload, DashboardPeriods $p): ?array
    {
        try {
            $_dim = $p->daysInMonth;
            $_dom = $p->dayOfMonth;
            $_lin = static fn(float $mtd): float => $_dom > 0 ? $mtd / $_dom * $_dim : $mtd;
            $_hp   = $payload['heatpump'];
            $_rest = $payload['rest'];
            $_ev   = $payload['ev'];
            return CostEstimates::dashboard($chargesDb, $powerlogDb, [
                'start'        => $p->sparkStart,
                'end'          => $p->today,
                'hpMtdKwh'     => $_hp['month_kwh'],
                'restMtdKwh'   => $_rest ? $_rest['month_kwh'] : 0.0,
                'hpProjKwh'    => $_hp['projected_kwh'] ?? $_lin($_hp['month_kwh']),
                'restProjKwh'  => $_rest ? ($_rest['projected_kwh'] ?? $_lin($_rest['month_kwh'])) : 0.0,
                'evMtdCost'    => $_ev['month_cost'],
                'evProjCost'   => $_ev['projected_cost'] ?? $_ev['month_cost'],
                'dayOfMonth'   => $_dom,
                'daysInMonth'  => $_dim,
                'perDay'       => Config::subscriptionPerDay(),
            ]);
        } catch (\Throwable $e) {
            error_log('dashboard cost estimate: ' . $e->getMessage());
            return null;
        }
    }
}
