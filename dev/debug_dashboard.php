<?php
/**
 * Debug script for dashboard comparison calculations.
 * Run from the project root:
 *   php debug_dashboard.php
 *
 * Requires:
 *   data/charging_data.db
 *   data/powerlog_data.db
 */

$chargesPath = dirname(__DIR__) . '/data/charging_data.db';
$powerlogPath = dirname(__DIR__) . '/data/powerlog_data.db';

foreach ([$chargesPath => 'charging_data.db', $powerlogPath => 'powerlog_data.db'] as $path => $label) {
    if (!file_exists($path)) {
        echo "FEJL: $label ikke fundet på $path\n";
        echo "Kopiér databasefilen dertil og kør scriptet igen.\n";
        exit(1);
    }
}

$chargesDb  = new PDO('sqlite:' . $chargesPath);
$powerlogDb = new PDO('sqlite:' . $powerlogPath);
$chargesDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$powerlogDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

date_default_timezone_set('Europe/Copenhagen');

$todayDt       = new DateTimeImmutable('today');
$currentStartDt = $todayDt->modify('first day of this month');
$currentDayIdx  = (int)$todayDt->format('j') - 1;

$prevStartDt    = $todayDt->modify('first day of last month');
$prevEndDt      = $prevStartDt->modify("+{$currentDayIdx} days");
$prevMonthEndDt = $prevStartDt->modify('last day of this month');
if ($prevEndDt > $prevMonthEndDt) $prevEndDt = $prevMonthEndDt;

$lastYearPeriodStartDt = $currentStartDt->modify('-1 year');
$lastYearPeriodEndDt   = $lastYearPeriodStartDt->modify("+{$currentDayIdx} days");
$lastYearMonthEndDt    = $lastYearPeriodStartDt->modify('last day of this month');
if ($lastYearPeriodEndDt > $lastYearMonthEndDt) $lastYearPeriodEndDt = $lastYearMonthEndDt;

$today              = $todayDt->format('Y-m-d');
$currentMonth       = $todayDt->format('Y-m');
$sparkStart         = $currentStartDt->format('Y-m-d');
$prevStart          = $prevStartDt->format('Y-m-d');
$prevEnd            = $prevEndDt->format('Y-m-d');
$lastYearPeriodStart = $lastYearPeriodStartDt->format('Y-m-d');
$lastYearPeriodEnd   = $lastYearPeriodEndDt->format('Y-m-d');
$lastYearMonthStart  = $lastYearPeriodStart;
$lastYearMonthEnd    = $lastYearMonthEndDt->format('Y-m-d');

echo "=== PERIODER ===\n";
echo "  Nu (MTD):              $sparkStart → $today  (dag $currentDayIdx+1)\n";
echo "  Forrige måned (MTD):   $prevStart → $prevEnd\n";
echo "  Samme måned sidste år (hel): $lastYearMonthStart → $lastYearMonthEnd\n";
echo "  Samme måned sidste år (MTD): $lastYearPeriodStart → $lastYearPeriodEnd\n\n";

// ── EV: interne ladninger ─────────────────────────────────────────────────────
echo "=== ELBIL: INTERNE LADNINGER ===\n";
$stmt = $chargesDb->prepare("
    SELECT startedAt, stoppedAt,
           COALESCE(consumedKwh,0) AS kwh,
           COALESCE(cost,0) AS cost
    FROM charges
    WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
");
$stmt->execute([$today, $lastYearMonthStart]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "  Rækker hentet fra DB: " . count($rows) . "\n";

$intMonthKwh = $intMonthCost = $intMonthCnt = 0;
$intPrevKwh  = $intPrevCost  = 0;
$intLYKwh    = $intLYCost    = 0;

foreach ($rows as $row) {
    $start = $row['startedAt'];
    $stop  = $row['stoppedAt'];
    $kwh   = floatval($row['kwh']);
    $cost  = floatval($row['cost']);

    // Simple proportional split by day
    $startTs = strtotime($start);
    $stopTs  = strtotime($stop);
    $totalSec = max(1, $stopTs - $startTs);
    $day = date('Y-m-d', $startTs);
    $countedForMonth = false;

    $cur = new DateTime($start);
    $end = new DateTime($stop);
    $cur->setTime((int)$cur->format('H'), (int)$cur->format('i'), (int)$cur->format('s'));

    // Walk day by day
    while ($cur <= $end) {
        $dayStr       = $cur->format('Y-m-d');
        $dayStartTs   = strtotime($dayStr . ' 00:00:00');
        $dayEndTs     = strtotime($dayStr . ' 23:59:59');
        $sliceStart   = max($startTs, $dayStartTs);
        $sliceEnd     = min($stopTs,  $dayEndTs);
        $sliceSec     = max(0, $sliceEnd - $sliceStart);
        $frac         = $sliceSec / $totalSec;
        $sliceKwh     = $kwh  * $frac;
        $sliceCost    = $cost * $frac;

        if ($dayStr >= $sparkStart && $dayStr <= $today) {
            $intMonthKwh  += $sliceKwh;
            $intMonthCost += $sliceCost;
            if (!$countedForMonth) { $intMonthCnt++; $countedForMonth = true; }
        }
        if ($dayStr >= $prevStart && $dayStr <= $prevEnd) {
            $intPrevKwh  += $sliceKwh;
            $intPrevCost += $sliceCost;
        }
        if ($dayStr >= $lastYearMonthStart && $dayStr <= $lastYearMonthEnd) {
            $intLYKwh  += $sliceKwh;
            $intLYCost += $sliceCost;
        }

        $cur->modify('+1 day');
        $cur->setTime(0,0,0);
    }
}
echo "  Denne måned (MTD):     " . round($intMonthKwh,2) . " kWh / " . round($intMonthCost,2) . " kr  ($intMonthCnt ladninger)\n";
echo "  Forrige måned (MTD):   " . round($intPrevKwh,2) . " kWh / " . round($intPrevCost,2) . " kr\n";
echo "  Samme måned i fjor:    " . round($intLYKwh,2) . " kWh / " . round($intLYCost,2) . " kr\n\n";

// ── EV: eksterne ladninger ────────────────────────────────────────────────────
echo "=== ELBIL: EKSTERNE LADNINGER (ext_charges) ===\n";
$stmt2 = $chargesDb->prepare("SELECT datetime, kwh, pris FROM ext_charges WHERE datetime >= ? ORDER BY datetime");
$stmt2->execute([$lastYearMonthStart]);
$extRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "  Rækker hentet: " . count($extRows) . "\n";

if (count($extRows) > 0) {
    echo "  Første: " . $extRows[0]['datetime'] . "  Seneste: " . end($extRows)['datetime'] . "\n";
    // Show a few samples
    echo "  Eksempler (datetime | kwh | pris):\n";
    foreach (array_slice($extRows, 0, 5) as $r) {
        echo "    " . $r['datetime'] . " | " . $r['kwh'] . " | " . $r['pris'] . "\n";
    }
}

$sparkStartTs = strtotime($sparkStart);
$todayEndTs   = strtotime($today . ' 23:59:59');
$prevStartTs  = strtotime($prevStart);
$prevEndTs    = strtotime($prevEnd . ' 23:59:59');
$lyStartTs    = strtotime($lastYearMonthStart);
$lyEndTs      = strtotime($lastYearMonthEnd . ' 23:59:59');

$extMonthKwh = $extMonthCost = $extMonthCnt = 0;
$extPrevKwh  = $extPrevCost  = 0;
$extLYKwh    = $extLYCost    = 0;

foreach ($extRows as $r) {
    $t = strtotime($r['datetime']);
    if ($t === false) {
        echo "  ADVARSEL: Kan ikke parse datetime: " . $r['datetime'] . "\n";
        continue;
    }
    $kwh  = floatval($r['kwh']);
    $pris = floatval($r['pris']);

    if ($t >= $sparkStartTs && $t <= $todayEndTs) {
        $extMonthKwh  += $kwh;
        $extMonthCost += $pris;
        $extMonthCnt++;
    }
    if ($t >= $prevStartTs && $t <= $prevEndTs) {
        $extPrevKwh  += $kwh;
        $extPrevCost += $pris;
    }
    if ($t >= $lyStartTs && $t <= $lyEndTs) {
        $extLYKwh  += $kwh;
        $extLYCost += $pris;
    }
}
echo "  Denne måned (MTD):   " . round($extMonthKwh,2) . " kWh / " . round($extMonthCost,2) . " kr  ($extMonthCnt ladninger)\n";
echo "  Forrige måned (MTD): " . round($extPrevKwh,2) . " kWh / " . round($extPrevCost,2) . " kr\n";
echo "  Samme måned i fjor:  " . round($extLYKwh,2) . " kWh / " . round($extLYCost,2) . " kr\n\n";

// ── EV: totaler og procenter ──────────────────────────────────────────────────
$totalKwh  = $intMonthKwh  + $extMonthKwh;
$totalCost = $intMonthCost + $extMonthCost;
$prevKwh   = $intPrevKwh   + $extPrevKwh;
$prevCost  = $intPrevCost  + $extPrevCost;
$lyKwh     = $intLYKwh     + $extLYKwh;
$lyCost    = $intLYCost    + $extLYCost;

echo "=== ELBIL: SAMLET ===\n";
echo "  Denne måned (MTD):     " . round($totalKwh,2) . " kWh / " . round($totalCost,2) . " kr\n";
echo "  Forrige måned (MTD):   " . round($prevKwh,2) . " kWh / " . round($prevCost,2) . " kr\n";
echo "  Samme måned i fjor:    " . round($lyKwh,2) . " kWh / " . round($lyCost,2) . " kr\n";
$pctMonthKwh  = $prevKwh > 0 ? round(($totalKwh - $prevKwh) / $prevKwh * 100, 1) : null;
$pctYearKwh   = $lyKwh   > 0 ? round(($totalKwh - $lyKwh)   / $lyKwh   * 100, 1) : null;
$pctMonthCost = $prevCost > 0 ? round(($totalCost - $prevCost) / $prevCost * 100, 1) : null;
$pctYearCost  = $lyCost   > 0 ? round(($totalCost - $lyCost)   / $lyCost   * 100, 1) : null;
echo "  kWh vs. sidst måned:   " . ($pctMonthKwh  !== null ? $pctMonthKwh.'%'  : 'null') . "\n";
echo "  kWh vs. sidste år:     " . ($pctYearKwh   !== null ? $pctYearKwh.'%'   : 'null') . "\n";
echo "  Pris vs. sidst måned:  " . ($pctMonthCost !== null ? $pctMonthCost.'%' : 'null') . "\n";
echo "  Pris vs. sidste år:    " . ($pctYearCost  !== null ? $pctYearCost.'%'  : 'null') . "\n\n";

// ── Jordvarme ─────────────────────────────────────────────────────────────────
echo "=== JORDVARME ===\n";

function hpQuery(PDO $db, string $rangeStart, string $rangeEnd, string $filterStart, string $filterEnd): float {
    $stmt = $db->prepare("
        WITH daily_max AS (
            SELECT DATE(logdate)  AS day,
                   MAX(kwh)       AS max_kwh
            FROM powerlogjord
            WHERE DATE(logdate) >= DATE(?, '-1 day')
              AND DATE(logdate) <= ?
            GROUP BY DATE(logdate)
        ),
        daily_delta AS (
            SELECT day,
                   MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
            FROM daily_max
        )
        SELECT ROUND(SUM(delta_kwh), 2) AS total_kwh
        FROM daily_delta
        WHERE DATE(day) BETWEEN ? AND ?
    ");
    $stmt->execute([$rangeStart, $rangeEnd, $filterStart, $filterEnd]);
    return floatval($stmt->fetchColumn());
}

$hpCurrent     = hpQuery($powerlogDb, $sparkStart,          $today,            $sparkStart,          $today);
$hpPrev        = hpQuery($powerlogDb, $prevStart,           $prevEnd,          $prevStart,           $prevEnd);
$hpLastYear    = hpQuery($powerlogDb, $lastYearMonthStart,  $lastYearMonthEnd, $lastYearMonthStart,  $lastYearMonthEnd);
$hpLastPeriod  = hpQuery($powerlogDb, $lastYearPeriodStart, $lastYearPeriodEnd,$lastYearPeriodStart, $lastYearPeriodEnd);

$hpPctMonth = $hpPrev > 0   ? round(($hpCurrent - $hpPrev) / $hpPrev * 100, 1)           : null;
$hpPctYear  = $hpLastYear > 0 ? round(($hpCurrent - $hpLastYear) / $hpLastYear * 100, 1) : null;

echo "  Denne måned (MTD):                     " . round($hpCurrent,2)    . " kWh\n";
echo "  Forrige måned (MTD):                   " . round($hpPrev,2)       . " kWh\n";
echo "  Samme måned i fjor (hele måneden):     " . round($hpLastYear,2)   . " kWh\n";
echo "  Samme måned i fjor (MTD til dag $currentDayIdx+1):  " . round($hpLastPeriod,2)  . " kWh\n";
echo "  % vs. sidst måned (MTD):              " . ($hpPctMonth !== null ? $hpPctMonth.'%' : 'null') . "\n";
echo "  % vs. samme måned i fjor (hel):       " . ($hpPctYear  !== null ? $hpPctYear.'%'  : 'null') . "\n\n";

// ── Rådata-tjek: antal rækker og datointerval ─────────────────────────────────
echo "=== RAW DATA TJEK ===\n";
$check = $powerlogDb->query("SELECT COUNT(*) AS cnt, MIN(DATE(logdate)) AS min_d, MAX(DATE(logdate)) AS max_d FROM powerlogjord")->fetch(PDO::FETCH_ASSOC);
echo "  powerlogjord rækker:  " . $check['cnt'] . "  fra " . $check['min_d'] . " til " . $check['max_d'] . "\n";
$check2 = $chargesDb->query("SELECT COUNT(*) AS cnt, MIN(DATE(startedAt)) AS min_d, MAX(DATE(stoppedAt)) AS max_d FROM charges")->fetch(PDO::FETCH_ASSOC);
echo "  charges rækker:       " . $check2['cnt'] . "  fra " . $check2['min_d'] . " til " . $check2['max_d'] . "\n";
try {
    $check3 = $chargesDb->query("SELECT COUNT(*) AS cnt, MIN(datetime) AS min_d, MAX(datetime) AS max_d FROM ext_charges")->fetch(PDO::FETCH_ASSOC);
    echo "  ext_charges rækker:   " . $check3['cnt'] . "  fra " . $check3['min_d'] . " til " . $check3['max_d'] . "\n";
} catch (Exception $e) {
    echo "  ext_charges: tabel ikke fundet eller fejl: " . $e->getMessage() . "\n";
}
echo "\nFærdig.\n";
