<?php
/**
 * Dashboard Summary API
 * Returns lightweight summary data for both EV and heatpump systems
 * for the current month + 14-day sparklines.
 */

require_once __DIR__ . '/../includes/Projection.php';

// ─── 5-minute file cache ──────────────────────────────────────────────────────
$_dashCacheKey = 'dashboard_' . date('Y-m-d');
$_cached = QueryBuilder::fileCacheRead($_dashCacheKey, CacheHelper::TTL_MEDIUM);
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
$prevMonthEnd  = $prevMonthEndDt->format('Y-m-d');
$lastYearPeriodStart = $lastYearPeriodStartDt->format('Y-m-d');
$lastYearPeriodEnd   = $lastYearPeriodEndDt->format('Y-m-d');
$lastYearMonthStart  = $lastYearPeriodStart;
$lastYearMonthEnd    = $lastYearMonthEndDt->format('Y-m-d');

$daysThisMonth = (int)date('d');
$daysInMonth   = (int)date('t');

// Today is only partly over. Averages, projections and "vs. previous month"
// comparisons must not treat it as a full day, otherwise everything looks too
// low (worst early in the day / month).
$_dayStartTs = $todayDt->getTimestamp();
$_dayLenSec  = $todayDt->modify('+1 day')->getTimestamp() - $_dayStartTs;   // 23/24/25 h (DST-safe)
$dayFraction = max(0.05, min(1.0, (time() - $_dayStartTs) / max(1, $_dayLenSec)));
// Elapsed days incl. the partial current day (floored so a run-rate computed
// shortly after midnight on the 1st can't explode).
$daysElapsed = max(0.25, ($daysThisMonth - 1) + $dayFraction);
// The comparison windows end on today's day-of-month, so their last day should
// only count pro rata — unless the other month is shorter and was capped, in
// which case its last day is a complete day.
$prevLastFrac = ($prevEndDt->format('j') === $todayDt->format('j'))           ? $dayFraction : 1.0;
$lyLastFrac   = ($lastYearPeriodEndDt->format('j') === $todayDt->format('j')) ? $dayFraction : 1.0;

/**
 * Project this month's total. Thin wrapper over Projection::fullMonth() (see
 * includes/Projection.php) that supplies the elapsed-day globals.
 * @return array{value:?float, basis:string}
 */
if (!function_exists('projectMonth')) {
function projectMonth(float $mtd, float $prevMtd, float $prevFull, float $lyMtd = 0.0, float $lyFull = 0.0): array {
    global $daysElapsed, $daysInMonth;
    return Projection::fullMonth($mtd, $prevMtd, $prevFull, $lyMtd, $lyFull, (float)$daysElapsed, (int)$daysInMonth);
}
}

/**
 * Sum a [Y-m-d => value] map, counting the window's final day only pro rata
 * ($lastFraction). Used for the "same period" comparison windows so a partly
 * elapsed today is compared with a partly elapsed day last month / last year.
 */
if (!function_exists('sumWithPartialLastDay')) {
function sumWithPartialLastDay(array $map, string $lastDay, float $lastFraction): float {
    $sum = 0.0;
    foreach ($map as $day => $value) {
        $sum += ($day === $lastDay) ? $value * $lastFraction : $value;
    }
    return $sum;
}
}

// =========================================================================
// EV — internal charges: raw fetch, proportional midnight-split
// =========================================================================
// A session that is still running has stoppedAt = NULL (the sync stores it and
// refreshes it on every run). Its end is taken as the last time Monta updated
// the record, so it counts up to "now" while charging, and a row that got stuck
// without ever being closed is still placed at its last known time instead of
// being dropped or stretched to today.
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
        if ($_endTs !== false && $_endTs >= time() - 1800) { $evChargingNow = true; }
    }
    $splits = QueryBuilder::splitChargeByDays(
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
            $w = ($day === $prevEnd) ? $prevLastFrac : 1.0;
            $intPrevKwh  += $slice['kwh']  * $w;
            $intPrevCost += $slice['cost'] * $w;
        }
        if ($day >= $prevStart && $day <= $prevMonthEnd) {
            $intPrevFullKwh  += $slice['kwh'];
            $intPrevFullCost += $slice['cost'];
        }
        if ($day >= $lastYearPeriodStart && $day <= $lastYearPeriodEnd) {
            $w = ($day === $lastYearPeriodEnd) ? $lyLastFrac : 1.0;
            $intLYKwh  += $slice['kwh']  * $w;
            $intLYCost += $slice['cost'] * $w;
        }
        if ($day >= $lastYearMonthStart && $day <= $lastYearMonthEnd) {
            $intLYFullKwh  += $slice['kwh'];
            $intLYFullCost += $slice['cost'];
        }
    }
}
$intMonth = ['cnt' => $intMonthCnt, 'kwh' => $intMonthKwh, 'cost' => $intMonthCost,
             'unpriced_cnt' => $intUnpricedCnt, 'unpriced_kwh' => $intUnpricedKwh];
$intPrev  = ['kwh' => $intPrevKwh,  'cost' => $intPrevCost];
$intLY    = ['kwh' => $intLYKwh,    'cost' => $intLYCost];

// External charges
$extRows = QueryBuilder::getExternalCharges($chargesDb, $lastYearMonthStart);

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
        $w = ($day === $prevEnd) ? $prevLastFrac : 1.0;
        $extPrevKwh  += floatval($row['kwh'])  * $w;
        $extPrevCost += floatval($row['pris']) * $w;
    }
    if ($day >= $prevStart && $day <= $prevMonthEnd) {
        $extPrevFullKwh  += floatval($row['kwh']);
        $extPrevFullCost += floatval($row['pris']);
    }
    if ($day >= $lastYearPeriodStart && $day <= $lastYearPeriodEnd) {
        $w = ($day === $lastYearPeriodEnd) ? $lyLastFrac : 1.0;
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
$extMonth = ['cnt' => $extMonthCnt, 'kwh' => $extMonthKwh, 'cost' => $extMonthCost];

// =========================================================================
// EV — sparkline: merge external charges into $evByDay
// =========================================================================
foreach ($extByDay as $day => $kwh) {
    $evByDay[$day] = ($evByDay[$day] ?? 0) + $kwh;
}
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
$hpPrev           = round(sumWithPartialLastDay($hpPrevMap, $prevEnd, $prevLastFrac), 2);

$hpPrevFullMap    = QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $prevStart, $prevMonthEnd);
$hpPrevFull       = round(array_sum($hpPrevFullMap), 2);

$hpLastYearMap    = QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $lastYearMonthStart, $lastYearMonthEnd);
$hpLastYear       = round(array_sum($hpLastYearMap), 2);

$hpLYPeriodMap    = QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $lastYearPeriodStart, $lastYearPeriodEnd);
$hpLastYearPeriod = round(sumWithPartialLastDay($hpLYPeriodMap, $lastYearPeriodEnd, $lyLastFrac), 2);

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
$husPrevFullKwh= null;
$husLastYearKwh= null;
$husLastYearFullKwh = null;
$husSparkline  = null;

try {
    $husDailyMap   = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $sparkWindowStart, $today);
    $husCurrentKwh = round(array_sum(array_filter($husDailyMap, function($v, $k) use ($sparkStart) {
        return $k >= $sparkStart;
    }, ARRAY_FILTER_USE_BOTH)), 2);

    $husPrevMap     = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $prevStart, $prevEnd);
    $husPrevKwh     = round(sumWithPartialLastDay($husPrevMap, $prevEnd, $prevLastFrac), 2);

    $husPrevFullMap = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $prevStart, $prevMonthEnd);
    $husPrevFullKwh = round(array_sum($husPrevFullMap), 2);

    $husLYMap       = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $lastYearPeriodStart, $lastYearPeriodEnd);
    $husLastYearKwh = round(sumWithPartialLastDay($husLYMap, $lastYearPeriodEnd, $lyLastFrac), 2);

    $husLYFullMap   = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $lastYearMonthStart, $lastYearMonthEnd);
    $husLastYearFullKwh = round(array_sum($husLYFullMap), 2);

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

$prevTotalCost = floatval($intPrev['cost']) + $extPrevCost;
$lyTotalCost   = floatval($intLY['cost'])   + $extLYCost;
$prevTotalKwh  = floatval($intPrev['kwh'])  + $extPrevKwh;
$lyTotalKwh    = floatval($intLY['kwh'])    + $extLYKwh;

$prevFullCost  = $intPrevFullCost + $extPrevFullCost;

// Same-month-last-year full totals — the seasonal projection anchor.
$lyFullCost    = $intLYFullCost + $extLYFullCost;

// Charges without a price count 0 kr; keep their kWh out of the kr/kWh denominators
// so missing prices don't drag the average down.
$_unpricedKwh        = floatval($intMonth['unpriced_kwh']);
$_pricedKwh          = $totalKwh - $_unpricedKwh;
$evCostPerKwh        = $_pricedKwh > 0.001 ? round($totalCost / $_pricedKwh, 3) : null;
$evHomePct           = $totalKwh > 0      ? round(floatval($intMonth['kwh']) / $totalKwh * 100, 1) : null;
$_evProj             = projectMonth($totalCost, $prevTotalCost, $prevFullCost, $lyTotalCost, $lyFullCost);
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
$prevExtPct = $prevTotalKwh > 0 ? round($extPrevKwh / $prevTotalKwh * 100, 1) : null;

$hpDailyAvg     = round($hpCurrent / $daysElapsed, 2);
$_hpProj         = projectMonth($hpCurrent, $hpPrev, $hpPrevFull, $hpLastYearPeriod, $hpLastYear);
$hpProjected     = $_hpProj['value'] !== null ? round($_hpProj['value'], 1) : null;

$husDailyAvg    = $husCurrentKwh !== null ? round($husCurrentKwh / $daysElapsed, 2) : null;
$_husProj         = $husCurrentKwh !== null
                    ? projectMonth($husCurrentKwh, floatval($husPrevKwh), floatval($husPrevFullKwh),
                                   floatval($husLastYearKwh), floatval($husLastYearFullKwh)) : null;
$husProjected   = ($_husProj !== null && $_husProj['value'] !== null) ? round($_husProj['value'], 1) : null;
$husPctChange   = ($husCurrentKwh !== null && $husPrevKwh > 0)
                    ? round(($husCurrentKwh - $husPrevKwh) / $husPrevKwh * 100, 1) : null;
$husPctChangeYear = ($husCurrentKwh !== null && $husLastYearKwh > 0)
                    ? round(($husCurrentKwh - $husLastYearKwh) / $husLastYearKwh * 100, 1) : null;

$response = json_encode([
    'ev' => [
        'month_charges'        => intval($intMonth['cnt']) + intval($extMonth['cnt']),
        'month_unpriced'       => intval($intMonth['unpriced_cnt']),   // charges without a price (cost = NULL)
        'charging_now'         => $evChargingNow,
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
        'prev_month_full_kwh' => round($hpPrevFull, 2),
        'last_year_month_kwh' => round($hpLastYear, 2),
        'last_year_period_kwh'=> round($hpLastYearPeriod, 2),
        'pct_change'          => $hpPctChange,
        'pct_change_year'     => $hpPctChangeYear,
        'daily_avg_kwh'       => $hpDailyAvg,
        'projected_kwh'       => $hpProjected,
        'projection_basis'    => $_hpProj['basis'],
        'sparkline'           => $hpSparkline,
    ],
    'hus' => $husCurrentKwh !== null ? [
        'month_kwh'       => round($husCurrentKwh, 2),
        'prev_month_full_kwh' => round(floatval($husPrevFullKwh), 2),
        'pct_change'      => $husPctChange,
        'pct_change_year' => $husPctChangeYear,
        'ev_home_kwh'     => round($homeKwh, 2),
        'hp_kwh'          => round($hpCurrent, 2),
        'daily_avg_kwh'   => $husDailyAvg,
        'projected_kwh'   => $husProjected,
        'projection_basis' => $_husProj['basis'] ?? null,
        'sparkline'       => $husSparkline,
    ] : null,
]);
QueryBuilder::fileCacheWrite($_dashCacheKey, $response);
echo $response;
