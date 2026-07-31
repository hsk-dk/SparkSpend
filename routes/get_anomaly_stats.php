<?php
/**
 * Anomaly Detection API
 *
 * Compares current-month-to-date daily average kWh against the same
 * calendar-month period last year for three categories:
 *   1. Hus (whole-house meter)
 *   2. Jordvarme (heat pump)
 *   3. El-bil (internal + external charges)
 */

// ─── 5-minute file cache (keyed by date) ──────────────────────────────────────
$_cacheKey = 'anomaly_' . date('Y-m-d');
$_cached   = QueryBuilder::fileCacheRead($_cacheKey, 300);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns sum of HDD for the given date range (inclusive), or null on failure.
 */
function _anomalyFetchHddSum(string $start, string $end, float $lat, float $lon): ?float
{
    $cacheDir  = rtrim($GLOBALS['cacheDir'] ?? sys_get_temp_dir(), '/\\');
    $cacheKey  = md5($start . '|' . $end . '|' . round($lat, 4) . '|' . round($lon, 4) . '|v1');
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_weather_' . $cacheKey . '.json';

    $json = null;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $json = file_get_contents($cacheFile);
    }

    if ($json === null) {
        $url = 'https://archive-api.open-meteo.com/v1/archive?' . http_build_query([
            'latitude'   => $lat,
            'longitude'  => $lon,
            'start_date' => $start,
            'end_date'   => $end,
            'daily'      => 'temperature_2m_mean',
            'timezone'   => 'Europe/Copenhagen',
        ]);
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $raw  = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['daily']['time'])) return null;

        $times  = $data['daily']['time']                ?? [];
        $temps  = $data['daily']['temperature_2m_mean'] ?? [];
        $recs   = [];
        foreach ($times as $i => $date) {
            $t = $temps[$i] ?? null;
            if ($t === null) continue;
            $recs[] = ['date' => $date, 'hdd' => round(max(0.0, 17.0 - (float)$t), 2), 'mean_temp_c' => round((float)$t, 1)];
        }
        $json = json_encode(['records' => $recs]);
        @file_put_contents($cacheFile, $json);
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded['records'] ?? null)) return null;

    $sum = 0.0;
    foreach ($decoded['records'] as $rec) {
        $sum += (float)($rec['hdd'] ?? 0);
    }
    return $sum;
}

$today      = new DateTimeImmutable('today');
$dayOfMonth = (int)$today->format('j');

// Require at least 5 days before raising alerts
if ($dayOfMonth < 5) {
    $json = json_encode(['anomalies' => [], 'insufficient_data' => true]);
    QueryBuilder::fileCacheWrite($_cacheKey, $json);
    echo $json;
    exit;
}

$chargesDb  = DatabaseManager::getChargesDb();
$powerlogDb = DatabaseManager::getPowerlogDb();

// Date ranges
$curMonthStart = $today->modify('first day of this month')->format('Y-m-d');
$curMonthEnd   = $today->format('Y-m-d');
$lyToday       = $today->modify('-1 year');
$lyMonthStart  = $lyToday->modify('first day of this month')->format('Y-m-d');
$lyMonthEnd    = $lyToday->format('Y-m-d');

$curDays = $dayOfMonth;
$lyDays  = $dayOfMonth;

// Severity thresholds
$threshCritical = 60;
$threshWarning  = 30;
$threshInfo     = 10;

$anomalies = [];

// =========================================================================
// Helper — build anomaly entry
// =========================================================================
$makeAnomaly = function (
    string $category,
    string $title,
    float $curKwh,
    float $lyKwh,
    string $navSection,
    ?string $navSub
) use ($curDays, $lyDays, $threshCritical, $threshWarning, $threshInfo): ?array {
    if ($lyKwh <= 0 || $curKwh <= 0) return null;

    $curAvg   = $curKwh / $curDays;
    $lyAvg    = $lyKwh  / $lyDays;
    $pctAbove = (int)round(($curAvg / $lyAvg - 1) * 100);

    if ($pctAbove < $threshInfo) return null;

    $severity = $pctAbove >= $threshCritical ? 'critical'
              : ($pctAbove >= $threshWarning  ? 'warning' : 'info');

    return [
        'category'     => $category,
        'severity'     => $severity,
        'title'        => $title,
        'message'      => 'Dagligt gennemsnit '
            . number_format($curAvg, 1, ',', '.')
            . ' kWh — ' . $pctAbove . '% over samme periode sidste år ('
            . number_format($lyAvg, 1, ',', '.') . ' kWh/dag).',
        'pct_above'    => $pctAbove,
        'current_avg'  => round($curAvg, 2),
        'baseline_avg' => round($lyAvg, 2),
        'nav_section'  => $navSection,
        'nav_sub'      => $navSub,
    ];
};

// =========================================================================
// 1. HUS — whole-house meter
// =========================================================================
try {
    $husCur = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $curMonthStart, $curMonthEnd));
    $husLy  = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $lyMonthStart, $lyMonthEnd));
    $a = $makeAnomaly('hus', 'Husforbrug', $husCur, $husLy, 'hus-section', 'hus-forbrug');
    if ($a) $anomalies[] = $a;
} catch (\Throwable $husEx) {
    error_log('getAnomalyStats hus: ' . $husEx->getMessage());
}

// =========================================================================
// 2. HP — heat pump (powerlogjord)
// =========================================================================
$hpCur = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $curMonthStart, $curMonthEnd));
$hpLy  = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $lyMonthStart, $lyMonthEnd));
$a = $makeAnomaly('hp', 'Jordvarme', $hpCur, $hpLy, 'jordvarme-section', null);
if ($a) $anomalies[] = $a;

// =========================================================================
// 3. EV — internal Monta charges + external charges
// =========================================================================
$evCurKwh = 0.0;
$evLyKwh  = 0.0;

$stmt = $chargesDb->prepare("
    SELECT startedAt, stoppedAt, COALESCE(consumedKwh, 0) AS kwh
    FROM charges
    WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
");
$stmt->execute([$curMonthEnd, $lyMonthStart]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $splits = QueryBuilder::splitChargeByDays(
        $row['startedAt'], $row['stoppedAt'],
        floatval($row['kwh']), 0.0
    );
    foreach ($splits as $day => $slice) {
        if ($day >= $curMonthStart && $day <= $curMonthEnd) $evCurKwh += $slice['kwh'];
        if ($day >= $lyMonthStart  && $day <= $lyMonthEnd)  $evLyKwh  += $slice['kwh'];
    }
}

$extRows = QueryBuilder::getExternalCharges($chargesDb, $lyMonthStart);
foreach ($extRows as $row) {
    $day = substr($row['datetime'], 0, 10);
    if ($day >= $curMonthStart && $day <= $curMonthEnd) $evCurKwh += floatval($row['kwh']);
    if ($day >= $lyMonthStart  && $day <= $lyMonthEnd)  $evLyKwh  += floatval($row['kwh']);
}

$a = $makeAnomaly('ev', 'El-bil', $evCurKwh, $evLyKwh, 'elbil-section', 'elbil-ladninger');
if ($a) $anomalies[] = $a;

// =========================================================================
// Sort: critical first, then warning, then info
// =========================================================================
$severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
usort($anomalies, fn($a, $b) => $severityRank[$a['severity']] <=> $severityRank[$b['severity']]);

// =========================================================================
// Possible cause: weather vs. consumption
// =========================================================================
if (!empty($anomalies)) {
    $lat = (float)($GLOBALS['weatherLat'] ?? 0);
    $lon = (float)($GLOBALS['weatherLon'] ?? 0);
    if ($lat !== 0.0 || $lon !== 0.0) {
        $hddCur = _anomalyFetchHddSum($curMonthStart, $curMonthEnd, $lat, $lon);
        $hddLy  = _anomalyFetchHddSum($lyMonthStart,  $lyMonthEnd,  $lat, $lon);
        $hddPct = ($hddLy > 0 && $hddCur !== null && $hddLy !== null)
            ? ($hddCur / $hddLy - 1) * 100
            : null;
        foreach ($anomalies as &$anomaly) {
            $anomaly['possible_cause'] = ($hddPct !== null && $hddPct >= 40.0) ? 'vejr' : 'forbrug';
        }
        unset($anomaly);
    }
}

$json = json_encode(['anomalies' => $anomalies, 'insufficient_data' => false]);
QueryBuilder::fileCacheWrite($_cacheKey, $json);
echo $json;
