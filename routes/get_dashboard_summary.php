<?php
/**
 * Dashboard Summary API
 * Returns lightweight summary data for both EV and heatpump systems
 * for the current month + 14-day sparklines.
 */

// ─── 60-second file cache ─────────────────────────────────────────────────────
$_dashCacheKey = 'dashboard_' . date('Y-m-d');
$_cached = QueryBuilder::fileCacheRead($_dashCacheKey, 60);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

$chargesDb = DatabaseManager::getChargesDb();
$powerlogDb = DatabaseManager::getPowerlogDb();

$todayDt       = new DateTimeImmutable('today');
$currentStartDt = $todayDt->modify('first day of this month');
$currentDayIdx = (int)$todayDt->format('j') - 1;

$prevStartDt   = $todayDt->modify('first day of last month');
$prevEndDt     = $prevStartDt->modify("+{$currentDayIdx} days");
$prevMonthEndDt = $prevStartDt->modify('last day of this month');
if ($prevEndDt > $prevMonthEndDt) {
    $prevEndDt = $prevMonthEndDt;
}

$lastYearPeriodStartDt = $currentStartDt->modify('-1 year');
$lastYearPeriodEndDt   = $lastYearPeriodStartDt->modify("+{$currentDayIdx} days");
$lastYearMonthEndDt = $lastYearPeriodStartDt->modify('last day of this month');
if ($lastYearPeriodEndDt > $lastYearMonthEndDt) {
    $lastYearPeriodEndDt = $lastYearMonthEndDt;
}

$currentMonth       = $todayDt->format('Y-m');
$today              = $todayDt->format('Y-m-d');
$sparkStart         = $currentStartDt->format('Y-m-d');
$sparkWindowStart   = $todayDt->modify('-29 days')->format('Y-m-d');
$prevStart          = $prevStartDt->format('Y-m-d');
$prevEnd       = $prevEndDt->format('Y-m-d');
$lastYearPeriodStart = $lastYearPeriodStartDt->format('Y-m-d');
$lastYearPeriodEnd   = $lastYearPeriodEndDt->format('Y-m-d');
$lastYearMonthStart  = $lastYearPeriodStart;
$lastYearMonthEnd    = $lastYearMonthEndDt->format('Y-m-d');

// =========================================================================
// EV — internal charges: raw fetch, proportional midnight-split
// =========================================================================
$stmtInt = $chargesDb->prepare("
    SELECT startedAt, stoppedAt,
           COALESCE(consumedKwh, 0) AS kwh,
           COALESCE(cost, 0) AS cost
    FROM charges
    WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
");
$stmtInt->execute([$today, $lastYearMonthStart]);

$intMonthCnt  = 0;  $intMonthKwh  = 0.0;  $intMonthCost = 0.0;
$intPrevKwh   = 0.0; $intPrevCost  = 0.0;
$intLYKwh     = 0.0; $intLYCost    = 0.0;
$evByDay      = [];

foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $splits = QueryBuilder::splitChargeByDays(
        $row['startedAt'], $row['stoppedAt'],
        floatval($row['kwh']), floatval($row['cost'])
    );
    $countedForMonth = false;
    foreach ($splits as $day => $slice) {
        if ($day >= $sparkWindowStart && $day <= $today) {
            $evByDay[$day] = ($evByDay[$day] ?? 0) + $slice['kwh'];
        }
        if ($day >= $sparkStart && $day <= $today) {
            $intMonthKwh  += $slice['kwh'];
            $intMonthCost += $slice['cost'];
            if (!$countedForMonth) { $intMonthCnt++; $countedForMonth = true; }
        }
        if ($day >= $prevStart && $day <= $prevEnd) {
            $intPrevKwh  += $slice['kwh'];
            $intPrevCost += $slice['cost'];
        }
        if ($day >= $lastYearPeriodStart && $day <= $lastYearPeriodEnd) {
            $intLYKwh  += $slice['kwh'];
            $intLYCost += $slice['cost'];
        }
    }
}
$intMonth = ['cnt' => $intMonthCnt, 'kwh' => $intMonthKwh, 'cost' => $intMonthCost];
$intPrev  = ['kwh' => $intPrevKwh,  'cost' => $intPrevCost];
$intLY    = ['kwh' => $intLYKwh,    'cost' => $intLYCost];

// External charges
$extRows = QueryBuilder::getExternalCharges($chargesDb, $lastYearMonthStart);

$extMonthCnt   = 0;
$extMonthKwh   = 0.0;
$extMonthCost  = 0.0;
$extPrevKwh    = 0.0;
$extPrevCost   = 0.0;
$extLYKwh      = 0.0;
$extLYCost     = 0.0;
$extByDay      = [];

foreach ($extRows as $row) {
    $t   = strtotime($row['datetime']);
    $day = date('Y-m-d', $t);
    if ($day >= $sparkStart && $day <= $today) {
        $extMonthCnt++;
        $extMonthKwh  += floatval($row['kwh']);
        $extMonthCost += floatval($row['pris']);
    }
    if ($day >= $prevStart && $day <= $prevEnd) {
        $extPrevKwh  += floatval($row['kwh']);
        $extPrevCost += floatval($row['pris']);
    }
    if ($day >= $lastYearPeriodStart && $day <= $lastYearPeriodEnd) {
        $extLYKwh  += floatval($row['kwh']);
        $extLYCost += floatval($row['pris']);
    }
    if ($day >= $sparkWindowStart && $day <= $today) {
        $extByDay[$day] = ($extByDay[$day] ?? 0) + floatval($row['kwh']);
    }
}
$extMonth = ['cnt' => $extMonthCnt, 'kwh' => $extMonthKwh, 'cost' => $extMonthCost];

// =========================================================================
// EV — sparkline: merge external charges into $evByDay
// =========================================================================
foreach ($extByDay as $day => $kwh) {
    $evByDay[$day] = ($evByDay[$day] ?? 0) + $kwh;
}
$daysThisMonth = (int)date('d');
$evSparkline   = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $evSparkline[] = round($evByDay[$day] ?? 0, 2);
}

// =========================================================================
// Heatpump — current and previous month totals + sparkline
// =========================================================================
$hpDailyMap = QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $sparkWindowStart, $today);
$hpCurrent  = round(array_sum(array_filter($hpDailyMap, function($v, $k) use ($sparkStart) {
    return $k >= $sparkStart;
}, ARRAY_FILTER_USE_BOTH)), 2);

$hpPrevMap        = QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $prevStart, $prevEnd);
$hpPrev           = round(array_sum($hpPrevMap), 2);

$hpLastYearMap    = QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $lastYearMonthStart, $lastYearMonthEnd);
$hpLastYear       = round(array_sum($hpLastYearMap), 2);

$hpLYPeriodMap    = QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $lastYearPeriodStart, $lastYearPeriodEnd);
$hpLastYearPeriod = round(array_sum($hpLYPeriodMap), 2);

$hpPctChange     = $hpPrev > 0
    ? round(($hpCurrent - $hpPrev) / $hpPrev * 100, 1)
    : null;
$hpPctChangeYear = $hpLastYearPeriod > 0
    ? round(($hpCurrent - $hpLastYearPeriod) / $hpLastYearPeriod * 100, 1)
    : null;

// Sparkline: rolling 30-day window
$hpSparkline = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $hpSparkline[] = round($hpDailyMap[$day] ?? 0, 2);
}

// =========================================================================
// House meter — current month, prev MTD, sparkline
// Wrapped in try-catch: if powerloghus table not yet synced, hus = null.
// =========================================================================
$husCurrentKwh = null;
$husPrevKwh    = null;
$husLastYearKwh= null;
$husSparkline  = null;

try {
    $husDailyMap   = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $sparkWindowStart, $today);
    $husCurrentKwh = round(array_sum(array_filter($husDailyMap, function($v, $k) use ($sparkStart) {
        return $k >= $sparkStart;
    }, ARRAY_FILTER_USE_BOTH)), 2);

    $husPrevMap     = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $prevStart, $prevEnd);
    $husPrevKwh     = round(array_sum($husPrevMap), 2);

    $husLYMap       = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $lastYearPeriodStart, $lastYearPeriodEnd);
    $husLastYearKwh = round(array_sum($husLYMap), 2);

    // Sparkline: rolling 30-day window
    $husSparkline = [];
    for ($i = 29; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $husSparkline[] = round($husDailyMap[$day] ?? 0, 2);
    }
} catch (Exception $husEx) {
    // powerloghus not yet synced — hus fields remain null
    error_log('getDashboardSummary: house power query failed — ' . $husEx->getMessage());
}

// =========================================================================
// Response
// =========================================================================
$totalKwh    = floatval($intMonth['kwh'])  + floatval($extMonth['kwh']);
$totalCost   = floatval($intMonth['cost']) + floatval($extMonth['cost']);
$daysInMonth = (int)date('t');

$prevTotalCost = floatval($intPrev['cost']) + $extPrevCost;
$lyTotalCost   = floatval($intLY['cost'])   + $extLYCost;
$prevTotalKwh  = floatval($intPrev['kwh'])  + $extPrevKwh;
$lyTotalKwh    = floatval($intLY['kwh'])    + $extLYKwh;

$evCostPerKwh        = $totalKwh > 0      ? round($totalCost / $totalKwh, 3) : null;
$evHomePct           = $totalKwh > 0      ? round(floatval($intMonth['kwh']) / $totalKwh * 100, 1) : null;
$evProjected         = $daysThisMonth > 0 ? round($totalCost / $daysThisMonth * $daysInMonth) : null;
$evPctChangeKwh      = $prevTotalKwh  > 0 ? round(($totalKwh - $prevTotalKwh) / $prevTotalKwh * 100, 1) : null;
$evPctChangeKwhYear  = $lyTotalKwh    > 0 ? round(($totalKwh - $lyTotalKwh)   / $lyTotalKwh   * 100, 1) : null;
$evPctChangeCost     = $prevTotalCost > 0 ? round(($totalCost - $prevTotalCost) / $prevTotalCost * 100, 1) : null;
$evPctChangeCostYear = $lyTotalCost   > 0 ? round(($totalCost - $lyTotalCost)   / $lyTotalCost   * 100, 1) : null;

// Home vs. external split details
$homeKwh    = floatval($intMonth['kwh']);
$homeCost   = floatval($intMonth['cost']);
$extKwh     = floatval($extMonth['kwh']);
$extCost    = floatval($extMonth['cost']);
$homeCpKwh  = $homeKwh > 0 ? round($homeCost / $homeKwh, 3) : null;
$extCpKwh   = $extKwh  > 0 ? round($extCost  / $extKwh,  3) : null;
$prevExtPct = $prevTotalKwh > 0 ? round($extPrevKwh / $prevTotalKwh * 100, 1) : null;

$hpDailyAvg     = $daysThisMonth > 0 ? round($hpCurrent / $daysThisMonth, 2) : null;
$hpProjected    = $daysThisMonth > 0 ? round($hpCurrent  / $daysThisMonth * $daysInMonth, 1) : null;

$husDailyAvg    = ($husCurrentKwh !== null && $daysThisMonth > 0)
                    ? round($husCurrentKwh / $daysThisMonth, 2) : null;
$husProjected   = ($husCurrentKwh !== null && $daysThisMonth > 0)
                    ? round($husCurrentKwh / $daysThisMonth * $daysInMonth, 1) : null;
$husPctChange   = ($husCurrentKwh !== null && $husPrevKwh > 0)
                    ? round(($husCurrentKwh - $husPrevKwh) / $husPrevKwh * 100, 1) : null;
$husPctChangeYear = ($husCurrentKwh !== null && $husLastYearKwh > 0)
                    ? round(($husCurrentKwh - $husLastYearKwh) / $husLastYearKwh * 100, 1) : null;

$response = json_encode([
    'ev' => [
        'month_charges'        => intval($intMonth['cnt']) + intval($extMonth['cnt']),
        'month_kwh'            => round($totalKwh, 2),
        'month_cost'           => round($totalCost, 2),
        'cost_per_kwh'         => $evCostPerKwh,
        'home_kwh_pct'         => $evHomePct,
        'projected_cost'       => $evProjected,
        'pct_change_kwh'       => $evPctChangeKwh,
        'pct_change_year_kwh'  => $evPctChangeKwhYear,
        'pct_change_cost'      => $evPctChangeCost,
        'pct_change_year_cost' => $evPctChangeCostYear,
        'sparkline'            => $evSparkline,
        // Home/external split
        'home_kwh'             => round($homeKwh, 2),
        'home_cost'            => round($homeCost, 2),
        'home_cpkwh'           => $homeCpKwh,
        'ext_kwh'              => round($extKwh, 2),
        'ext_cost'             => round($extCost, 2),
        'ext_cpkwh'            => $extCpKwh,
        'ext_cnt'              => intval($extMonth['cnt']),
        'prev_ext_kwh_pct'     => $prevExtPct,
    ],
    'heatpump' => [
        'month_kwh'           => round($hpCurrent, 2),
        'prev_month_kwh'      => round($hpPrev, 2),
        'last_year_month_kwh' => round($hpLastYear, 2),
        'last_year_period_kwh'=> round($hpLastYearPeriod, 2),
        'pct_change'          => $hpPctChange,
        'pct_change_year'     => $hpPctChangeYear,
        'daily_avg_kwh'       => $hpDailyAvg,
        'projected_kwh'       => $hpProjected,
        'sparkline'           => $hpSparkline,
    ],
    'hus' => $husCurrentKwh !== null ? [
        'month_kwh'       => round($husCurrentKwh, 2),
        'pct_change'      => $husPctChange,
        'pct_change_year' => $husPctChangeYear,
        'ev_home_kwh'     => round($homeKwh, 2),
        'hp_kwh'          => round($hpCurrent, 2),
        'daily_avg_kwh'   => $husDailyAvg,
        'projected_kwh'   => $husProjected,
        'sparkline'       => $husSparkline,
    ] : null,
]);
QueryBuilder::fileCacheWrite($_dashCacheKey, $response);
echo $response;
