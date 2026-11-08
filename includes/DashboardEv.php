<?php
/**
 * The EV card of the dashboard: this month's charging (home + away) against last month and last year.
 * collect() reads and sums the charges; payload() turns the sums into the card. Was the first half of
 * DashboardSummary::build(); the arithmetic is unchanged (dev/golden/ proves it).
 */
final class DashboardEv
{
    /**
     * @return array{
     *   intMonth:array, intPrev:array, intLY:array, intPrevFull:array, intLYFull:array,
     *   extMonth:array, extPrev:array, extPrevFull:array, extLY:array, extLYFull:array,
     *   intByDay:array<string,float>, sparkline:array<int,float|int>, chargingNow:bool
     * }
     *   int* = home (internal) charging, ext* = away; Prev/LY = the same stretch last month / last year, *Full = the
     *   whole of that month. intByDay is home charging only: that is what the house meter contains.
     */
    public static function collect(PDO $chargesDb, DashboardPeriods $p): array
    {
        $today = $p->today;
        $sparkStart = $p->sparkStart; $sparkWindowStart = $p->sparkWindowStart;
        $prevStart = $p->prevStart; $prevEnd = $p->prevEnd; $prevMonthEnd = $p->prevMonthEnd;
        $lastYearPeriodStart = $p->lastYearPeriodStart; $lastYearPeriodEnd = $p->lastYearPeriodEnd;
        $lastYearMonthStart = $p->lastYearMonthStart; $lastYearMonthEnd = $p->lastYearMonthEnd;
        $prevLastFrac = $p->prevLastFrac; $lyLastFrac = $p->lyLastFrac;
        $prevScale = $p->prevScale; $lyScale = $p->lyScale;

        // =========================================================================
        // Internal charges: raw fetch, proportional midnight-split
        // =========================================================================
        // A session that is still running has stoppedAt = NULL (the sync stores it and refreshes it on every run).
        // Its end is taken as the last time Monta updated the record, so it counts up to "now" while charging, and
        // a row that got stuck without ever being closed is still placed at its last known time instead of being
        // dropped or stretched to today.
        $stmtInt = $chargesDb->prepare("
            SELECT startedAt,
                   COALESCE(stoppedAt, updatedAt, startedAt) AS endedAt,
                   (stoppedAt IS NULL) AS ongoing,
                   COALESCE(consumedKwh, 0) AS kwh,
                   COALESCE(cost, 0) AS cost,
                   (cost IS NULL) AS noCost
            FROM charges
            WHERE DATE(startedAt) <= ? AND DATE(COALESCE(stoppedAt, updatedAt, startedAt)) >= ?
              AND consumedKwh > 0          -- same rule as the charge list (showZeroKwh = off)
        ");
        $stmtInt->execute([$today, $lastYearMonthStart]);

        $intMonthCnt  = 0;  $intMonthKwh  = 0.0;  $intMonthCost = 0.0;
        $intUnpricedCnt = 0; $intUnpricedKwh = 0.0;   // charges with cost IS NULL
        $intPrevKwh   = 0.0; $intPrevCost  = 0.0;
        $intPrevFullKwh = 0.0; $intPrevFullCost = 0.0;
        $intLYKwh     = 0.0; $intLYCost    = 0.0;
        $intLYFullKwh = 0.0; $intLYFullCost = 0.0;
        $evByDay      = [];
        $evChargingNow = false;   // an open session that Monta touched within the last 30 minutes

        foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)$row['ongoing'] === 1) {
                $_endTs = strtotime($row['endedAt']);
                if ($_endTs !== false && $_endTs >= $p->now - 1800) { $evChargingNow = true; }
            }
            $splits = DateHelper::splitChargeByDays(
                $row['startedAt'], $row['endedAt'],
                floatval($row['kwh']), floatval($row['cost'])
            );
            $countedForMonth = false;
            $noCost = ((int)$row['noCost'] === 1);
            foreach ($splits as $day => $slice) {
                if ($day >= $sparkWindowStart && $day <= $today) {
                    $evByDay[$day] = ($evByDay[$day] ?? 0) + $slice['kwh'];
                }
                if ($day >= $sparkStart && $day <= $today) {
                    $intMonthKwh  += $slice['kwh'];
                    $intMonthCost += $slice['cost'];
                    if ($noCost) { $intUnpricedKwh += $slice['kwh']; }
                    if (!$countedForMonth) {
                        $intMonthCnt++; $countedForMonth = true;
                        if ($noCost) { $intUnpricedCnt++; }
                    }
                }
                if ($day >= $prevStart && $day <= $prevEnd) {
                    $w = (($day === $prevEnd) ? $prevLastFrac : 1.0) * $prevScale;
                    $intPrevKwh  += $slice['kwh']  * $w;
                    $intPrevCost += $slice['cost'] * $w;
                }
                if ($day >= $prevStart && $day <= $prevMonthEnd) {
                    $intPrevFullKwh  += $slice['kwh'];
                    $intPrevFullCost += $slice['cost'];
                }
                if ($day >= $lastYearPeriodStart && $day <= $lastYearPeriodEnd) {
                    $w = (($day === $lastYearPeriodEnd) ? $lyLastFrac : 1.0) * $lyScale;
                    $intLYKwh  += $slice['kwh']  * $w;
                    $intLYCost += $slice['cost'] * $w;
                }
                if ($day >= $lastYearMonthStart && $day <= $lastYearMonthEnd) {
                    $intLYFullKwh  += $slice['kwh'];
                    $intLYFullCost += $slice['cost'];
                }
            }
        }

        // =========================================================================
        // External charges
        // =========================================================================
        $extRows = ChargeRepository::getExternal($chargesDb, $lastYearMonthStart);

        $extMonthCnt   = 0;
        $extMonthKwh   = 0.0;
        $extMonthCost  = 0.0;
        $extPrevKwh    = 0.0;
        $extPrevCost   = 0.0;
        $extPrevFullKwh  = 0.0;
        $extPrevFullCost = 0.0;
        $extLYKwh      = 0.0;
        $extLYCost     = 0.0;
        $extLYFullKwh  = 0.0;
        $extLYFullCost = 0.0;
        $extByDay      = [];

        foreach ($extRows as $row) {
            if (floatval($row['kwh']) <= 0) continue;   // same rule as the charge list
            $t   = strtotime($row['datetime']);
            $day = date('Y-m-d', $t);
            if ($day >= $sparkStart && $day <= $today) {
                $extMonthCnt++;
                $extMonthKwh  += floatval($row['kwh']);
                $extMonthCost += floatval($row['pris']);
            }
            if ($day >= $prevStart && $day <= $prevEnd) {
                $w = (($day === $prevEnd) ? $prevLastFrac : 1.0) * $prevScale;
                $extPrevKwh  += floatval($row['kwh'])  * $w;
                $extPrevCost += floatval($row['pris']) * $w;
            }
            if ($day >= $prevStart && $day <= $prevMonthEnd) {
                $extPrevFullKwh  += floatval($row['kwh']);
                $extPrevFullCost += floatval($row['pris']);
            }
            if ($day >= $lastYearPeriodStart && $day <= $lastYearPeriodEnd) {
                $w = (($day === $lastYearPeriodEnd) ? $lyLastFrac : 1.0) * $lyScale;
                $extLYKwh  += floatval($row['kwh'])  * $w;
                $extLYCost += floatval($row['pris']) * $w;
            }
            if ($day >= $lastYearMonthStart && $day <= $lastYearMonthEnd) {
                $extLYFullKwh  += floatval($row['kwh']);
                $extLYFullCost += floatval($row['pris']);
            }
            if ($day >= $sparkWindowStart && $day <= $today) {
                $extByDay[$day] = ($extByDay[$day] ?? 0) + floatval($row['kwh']);
            }
        }

        // Sparkline: external charges merged into the per-day map (home charging alone stays in $intByDay)
        $intByDay = $evByDay;
        foreach ($extByDay as $day => $kwh) {
            $evByDay[$day] = ($evByDay[$day] ?? 0) + $kwh;
        }

        return [
            'intMonth'    => ['cnt' => $intMonthCnt, 'kwh' => $intMonthKwh, 'cost' => $intMonthCost,
                              'unpriced_cnt' => $intUnpricedCnt, 'unpriced_kwh' => $intUnpricedKwh],
            'intPrev'     => ['kwh' => $intPrevKwh, 'cost' => $intPrevCost],
            'intLY'       => ['kwh' => $intLYKwh, 'cost' => $intLYCost],
            'intPrevFull' => ['kwh' => $intPrevFullKwh, 'cost' => $intPrevFullCost],
            'intLYFull'   => ['kwh' => $intLYFullKwh, 'cost' => $intLYFullCost],
            'extMonth'    => ['cnt' => $extMonthCnt, 'kwh' => $extMonthKwh, 'cost' => $extMonthCost],
            'extPrev'     => ['kwh' => $extPrevKwh, 'cost' => $extPrevCost],
            'extPrevFull' => ['kwh' => $extPrevFullKwh, 'cost' => $extPrevFullCost],
            'extLY'       => ['kwh' => $extLYKwh, 'cost' => $extLYCost],
            'extLYFull'   => ['kwh' => $extLYFullKwh, 'cost' => $extLYFullCost],
            'intByDay'    => $intByDay,
            'sparkline'   => $p->sparkline($evByDay),
            'chargingNow' => $evChargingNow,
        ];
    }

    /** The 'ev' block of the payload. */
    public static function payload(array $r, DashboardPeriods $p): array
    {
        $intMonth = $r['intMonth']; $extMonth = $r['extMonth'];
        $intPrev = $r['intPrev']; $intLY = $r['intLY'];

        $totalKwh    = floatval($intMonth['kwh'])  + floatval($extMonth['kwh']);
        $totalCost   = floatval($intMonth['cost']) + floatval($extMonth['cost']);

        $prevTotalCost = floatval($intPrev['cost']) + $r['extPrev']['cost'];
        $lyTotalCost   = floatval($intLY['cost'])   + $r['extLY']['cost'];
        $prevTotalKwh  = floatval($intPrev['kwh'])  + $r['extPrev']['kwh'];
        $lyTotalKwh    = floatval($intLY['kwh'])    + $r['extLY']['kwh'];

        $prevFullCost  = $r['intPrevFull']['cost'] + $r['extPrevFull']['cost'];

        // Same-month-last-year full totals - the seasonal projection anchor.
        $lyFullCost    = $r['intLYFull']['cost'] + $r['extLYFull']['cost'];

        // Charges without a price count 0 kr; keep their kWh out of the kr/kWh denominators so missing prices don't
        // drag the average down.
        $_unpricedKwh        = floatval($intMonth['unpriced_kwh']);
        $_pricedKwh          = $totalKwh - $_unpricedKwh;
        $evCostPerKwh        = $_pricedKwh > 0.001 ? round($totalCost / $_pricedKwh, 3) : null;
        $evHomePct           = $totalKwh > 0      ? round(floatval($intMonth['kwh']) / $totalKwh * 100, 1) : null;
        $_evProj             = $p->projectMonth($totalCost, $prevTotalCost, $prevFullCost, $lyTotalCost, $lyFullCost);
        $evProjected         = $_evProj['value'] !== null ? round($_evProj['value']) : null;
        $evPctChangeKwh      = $prevTotalKwh  > 0 ? round(($totalKwh - $prevTotalKwh) / $prevTotalKwh * 100, 1) : null;
        $evPctChangeKwhYear  = $lyTotalKwh    > 0 ? round(($totalKwh - $lyTotalKwh)   / $lyTotalKwh   * 100, 1) : null;
        $evPctChangeCost     = $prevTotalCost > 0 ? round(($totalCost - $prevTotalCost) / $prevTotalCost * 100, 1) : null;
        $evPctChangeCostYear = $lyTotalCost   > 0 ? round(($totalCost - $lyTotalCost)   / $lyTotalCost   * 100, 1) : null;

        // Home vs. external split details
        $homeKwh    = floatval($intMonth['kwh']);
        $homeCost   = floatval($intMonth['cost']);
        $extKwh     = floatval($extMonth['kwh']);
        $extCost    = floatval($extMonth['cost']);
        $homeCpKwh  = ($homeKwh - $_unpricedKwh) > 0.001 ? round($homeCost / ($homeKwh - $_unpricedKwh), 3) : null;
        $extCpKwh   = $extKwh  > 0 ? round($extCost  / $extKwh,  3) : null;
        $prevExtPct = $prevTotalKwh > 0 ? round($r['extPrev']['kwh'] / $prevTotalKwh * 100, 1) : null;

        return [
            'month_charges'        => intval($intMonth['cnt']) + intval($extMonth['cnt']),
            'month_unpriced'       => intval($intMonth['unpriced_cnt']),   // charges without a price (cost = NULL)
            'charging_now'         => $r['chargingNow'],
            'month_kwh'            => round($totalKwh, 2),
            'month_cost'           => round($totalCost, 2),
            'cost_per_kwh'         => $evCostPerKwh,
            'home_kwh_pct'         => $evHomePct,
            'projected_cost'       => $evProjected,
            'projection_basis'     => $_evProj['basis'],
            'pct_change_kwh'       => $evPctChangeKwh,
            'pct_change_year_kwh'  => $evPctChangeKwhYear,
            'pct_change_cost'      => $evPctChangeCost,
            'pct_change_year_cost' => $evPctChangeCostYear,
            'sparkline'            => $r['sparkline'],
            // Home/external split
            'home_kwh'             => round($homeKwh, 2),
            'home_cost'            => round($homeCost, 2),
            'home_cpkwh'           => $homeCpKwh,
            'ext_kwh'              => round($extKwh, 2),
            'ext_cost'             => round($extCost, 2),
            'ext_cpkwh'            => $extCpKwh,
            'ext_cnt'              => intval($extMonth['cnt']),
            'prev_ext_kwh_pct'     => $prevExtPct,
        ];
    }
}
