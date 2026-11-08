<?php
/**
 * The meter cards of the dashboard: the heat pump, the house, and "rest" (house - heat pump - home EV charging).
 * Each has a read step (collect*) that sums the meter and a payload step that makes the card. Was the second half
 * of DashboardSummary::build(); the arithmetic is unchanged (dev/golden/ proves it).
 */
final class DashboardMeters
{
    // ── Heat pump ────────────────────────────────────────────────────────────────────────────────

    /** @return array{daily:array<string,float>,current:float,prev:float,prevFull:float,lastYear:float,lastYearPeriod:float} */
    public static function collectHeatpump(PDO $powerlogDb, DashboardPeriods $p): array
    {
        $hpDailyMap = PowerlogRepository::lagDelta($powerlogDb, 'powerlogjord', $p->meterWindowStart, $p->today);
        $hpCurrent  = round(array_sum(array_filter($hpDailyMap, function($v, $k) use ($p) {
            return $k >= $p->sparkStart;
        }, ARRAY_FILTER_USE_BOTH)), 2);

        $hpPrevMap        = PowerlogRepository::lagDelta($powerlogDb, 'powerlogjord', $p->prevStart, $p->prevEnd);
        $hpPrev           = round(DashboardPeriods::sumWithPartialLastDay($hpPrevMap, $p->prevEnd, $p->prevLastFrac) * $p->prevScale, 2);

        $hpPrevFullMap    = PowerlogRepository::lagDelta($powerlogDb, 'powerlogjord', $p->prevStart, $p->prevMonthEnd);
        $hpPrevFull       = round(array_sum($hpPrevFullMap), 2);

        $hpLastYearMap    = PowerlogRepository::lagDelta($powerlogDb, 'powerlogjord', $p->lastYearMonthStart, $p->lastYearMonthEnd);
        $hpLastYear       = round(array_sum($hpLastYearMap), 2);

        $hpLYPeriodMap    = PowerlogRepository::lagDelta($powerlogDb, 'powerlogjord', $p->lastYearPeriodStart, $p->lastYearPeriodEnd);
        $hpLastYearPeriod = round(DashboardPeriods::sumWithPartialLastDay($hpLYPeriodMap, $p->lastYearPeriodEnd, $p->lyLastFrac) * $p->lyScale, 2);

        return ['daily' => $hpDailyMap, 'current' => $hpCurrent, 'prev' => $hpPrev, 'prevFull' => $hpPrevFull,
                'lastYear' => $hpLastYear, 'lastYearPeriod' => $hpLastYearPeriod];
    }

    public static function heatpumpPayload(array $hp, DashboardPeriods $p): array
    {
        $hpCurrent = $hp['current']; $hpPrev = $hp['prev']; $hpLastYearPeriod = $hp['lastYearPeriod'];
        $hpPctChange     = $hpPrev > 0
            ? round(($hpCurrent - $hpPrev) / $hpPrev * 100, 1)
            : null;
        $hpPctChangeYear = $hpLastYearPeriod > 0
            ? round(($hpCurrent - $hpLastYearPeriod) / $hpLastYearPeriod * 100, 1)
            : null;
        $hpDailyAvg     = round($hpCurrent / $p->daysElapsed, 2);
        $_hpProj         = $p->projectMonth($hpCurrent, $hpPrev, $hp['prevFull'], $hpLastYearPeriod, $hp['lastYear']);
        $hpProjected     = $_hpProj['value'] !== null ? round($_hpProj['value'], 1) : null;

        return [
            'month_kwh'           => round($hpCurrent, 2),
            'prev_month_kwh'      => round($hpPrev, 2),
            'prev_month_full_kwh' => round($hp['prevFull'], 2),
            'last_year_month_kwh' => round($hp['lastYear'], 2),
            'last_year_period_kwh'=> round($hpLastYearPeriod, 2),
            'pct_change'          => $hpPctChange,
            'pct_change_year'     => $hpPctChangeYear,
            'daily_avg_kwh'       => $hpDailyAvg,
            'projected_kwh'       => $hpProjected,
            'projection_basis'    => $_hpProj['basis'],
            'sparkline'           => $p->sparkline($hp['daily']),   // rolling 30-day window
        ];
    }

    // ── House ────────────────────────────────────────────────────────────────────────────────────

    /**
     * The house meter. If the powerloghus table has not been synced yet the query throws and every figure stays
     * null ("hus" is then null in the payload, and so is "rest").
     *
     * @return array{daily:array<string,float>,current:?float,prev:?float,prevFull:?float,lastYear:?float,lastYearFull:?float,sparkline:?array}
     */
    public static function collectHouse(PDO $powerlogDb, DashboardPeriods $p): array
    {
        $husCurrentKwh = null;
        $husPrevKwh    = null;
        $husPrevFullKwh= null;
        $husLastYearKwh= null;
        $husLastYearFullKwh = null;
        $husSparkline  = null;
        $husDailyMap   = [];   // filled inside the try; consumers read it only when $husCurrentKwh !== null

        try {
            $husDailyMap   = PowerlogRepository::lagDelta($powerlogDb, 'powerloghus', $p->meterWindowStart, $p->today);
            $husCurrentKwh = round(array_sum(array_filter($husDailyMap, function($v, $k) use ($p) {
                return $k >= $p->sparkStart;
            }, ARRAY_FILTER_USE_BOTH)), 2);

            $husPrevMap     = PowerlogRepository::lagDelta($powerlogDb, 'powerloghus', $p->prevStart, $p->prevEnd);
            $husPrevKwh     = round(DashboardPeriods::sumWithPartialLastDay($husPrevMap, $p->prevEnd, $p->prevLastFrac) * $p->prevScale, 2);

            $husPrevFullMap = PowerlogRepository::lagDelta($powerlogDb, 'powerloghus', $p->prevStart, $p->prevMonthEnd);
            $husPrevFullKwh = round(array_sum($husPrevFullMap), 2);

            $husLYMap       = PowerlogRepository::lagDelta($powerlogDb, 'powerloghus', $p->lastYearPeriodStart, $p->lastYearPeriodEnd);
            $husLastYearKwh = round(DashboardPeriods::sumWithPartialLastDay($husLYMap, $p->lastYearPeriodEnd, $p->lyLastFrac) * $p->lyScale, 2);

            $husLYFullMap   = PowerlogRepository::lagDelta($powerlogDb, 'powerloghus', $p->lastYearMonthStart, $p->lastYearMonthEnd);
            $husLastYearFullKwh = round(array_sum($husLYFullMap), 2);

            $husSparkline = $p->sparkline($husDailyMap);   // rolling 30-day window
        } catch (Exception $husEx) {
            // powerloghus not yet synced - hus fields remain null
            error_log('getDashboardSummary: house power query failed - ' . $husEx->getMessage());
        }

        return ['daily' => $husDailyMap, 'current' => $husCurrentKwh, 'prev' => $husPrevKwh, 'prevFull' => $husPrevFullKwh,
                'lastYear' => $husLastYearKwh, 'lastYearFull' => $husLastYearFullKwh, 'sparkline' => $husSparkline];
    }

    /** The 'hus' block, or null without house data. $homeEvKwh = this month's home EV charging. */
    public static function housePayload(array $hus, float $hpMonthKwh, float $homeEvKwh, DashboardPeriods $p): ?array
    {
        $husCurrentKwh = $hus['current'];
        if ($husCurrentKwh === null) return null;

        $husPrevKwh = $hus['prev']; $husPrevFullKwh = $hus['prevFull'];
        $husLastYearKwh = $hus['lastYear']; $husLastYearFullKwh = $hus['lastYearFull'];

        $husDailyAvg    = round($husCurrentKwh / $p->daysElapsed, 2);
        $_husProj       = $p->projectMonth($husCurrentKwh, floatval($husPrevKwh), floatval($husPrevFullKwh),
                                           floatval($husLastYearKwh), floatval($husLastYearFullKwh));
        $husProjected   = $_husProj['value'] !== null ? round($_husProj['value'], 1) : null;
        $husPctChange   = ($husPrevKwh > 0)
                            ? round(($husCurrentKwh - $husPrevKwh) / $husPrevKwh * 100, 1) : null;
        $husPctChangeYear = ($husLastYearKwh > 0)
                            ? round(($husCurrentKwh - $husLastYearKwh) / $husLastYearKwh * 100, 1) : null;

        return [
            'month_kwh'       => round($husCurrentKwh, 2),
            'prev_month_full_kwh' => round(floatval($husPrevFullKwh), 2),
            'pct_change'      => $husPctChange,
            'pct_change_year' => $husPctChangeYear,
            'ev_home_kwh'     => round($homeEvKwh, 2),
            'hp_kwh'          => round($hpMonthKwh, 2),
            'daily_avg_kwh'   => $husDailyAvg,
            'projected_kwh'   => $husProjected,
            'projection_basis' => $_husProj['basis'] ?? null,
            'sparkline'       => $hus['sparkline'],
        ];
    }

    // ── Rest ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * Rest = house meter - heat pump - EV home charging. The Hus card shows ONLY this: EV and the heat pump have
     * their own cards, so repeating them in the house figures just muddles the picture. Every period is derived the
     * same way (same pro-rata day, same clamp at 0), so the trends, projection and sparkline are consistent with the
     * monthly figure. Null without house data.
     *
     * @param array $ev the EV sums from DashboardEv::collect()
     */
    public static function restPayload(array $hus, array $hp, array $ev, DashboardPeriods $p): ?array
    {
        $husCurrentKwh = $hus['current'];
        if ($husCurrentKwh === null) return null;

        $husPrevKwh = $hus['prev']; $husPrevFullKwh = $hus['prevFull'];
        $husLastYearKwh = $hus['lastYear']; $husLastYearFullKwh = $hus['lastYearFull'];
        $husDailyMap = $hus['daily']; $hpDailyMap = $hp['daily']; $intByDay = $ev['intByDay'];

        $restOf = static fn(float $house, float $heatPump, float $evHome): float => max(0.0, $house - $heatPump - $evHome);

        $restCurrent  = $restOf($husCurrentKwh,                    $hp['current'],        floatval($ev['intMonth']['kwh']));
        $restPrev     = $restOf(floatval($husPrevKwh),             $hp['prev'],           floatval($ev['intPrev']['kwh']));
        $restPrevFull = $restOf(floatval($husPrevFullKwh),         $hp['prevFull'],       $ev['intPrevFull']['kwh']);
        $restLY       = $restOf(floatval($husLastYearKwh),         $hp['lastYearPeriod'], floatval($ev['intLY']['kwh']));
        $restLYFull   = $restOf(floatval($husLastYearFullKwh),     $hp['lastYear'],       $ev['intLYFull']['kwh']);

        $restSparkline = [];
        foreach ($p->last30Days as $day) {
            $restSparkline[] = round($restOf(floatval($husDailyMap[$day] ?? 0), floatval($hpDailyMap[$day] ?? 0), floatval($intByDay[$day] ?? 0)), 2);
        }

        // Data quality: days (this month) where house - heat pump - EV is clearly negative are clamped to 0 above.
        // That means the meters disagree (EV not on the house meter, a sync gap, ...) - surface it instead of hiding it.
        $restNegDays = 0; $restNegKwh = 0.0;
        $monthStartStr = date('Y-m-01', $p->now);
        foreach ($husDailyMap as $day => $houseKwh) {
            if ($day < $monthStartStr) continue;
            $raw = floatval($houseKwh) - floatval($hpDailyMap[$day] ?? 0) - floatval($intByDay[$day] ?? 0);
            if ($raw < -0.5) { $restNegDays++; $restNegKwh += -$raw; }
        }

        $_restProj = $p->projectMonth($restCurrent, $restPrev, $restPrevFull, $restLY, $restLYFull);
        return [
            'month_kwh'           => round($restCurrent, 2),
            'daily_avg_kwh'       => round($restCurrent / $p->daysElapsed, 2),
            'projected_kwh'       => $_restProj['value'] !== null ? round($_restProj['value'], 1) : null,
            'projection_basis'    => $_restProj['basis'],
            'prev_month_full_kwh' => round($restPrevFull, 2),
            'pct_change'          => $restPrev > 0 ? round(($restCurrent - $restPrev) / $restPrev * 100, 1) : null,
            'pct_change_year'     => $restLY   > 0 ? round(($restCurrent - $restLY)   / $restLY   * 100, 1) : null,
            'sparkline'           => $restSparkline,
            'negative_days'       => $restNegDays,
            'negative_kwh'        => round($restNegKwh, 2),
        ];
    }
}
