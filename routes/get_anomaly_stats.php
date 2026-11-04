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
ApiCache::serve($_cacheKey, CacheHelper::TTL_MEDIUM);
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns sum of HDD for the given date range (inclusive), or null on failure.
 */
function _anomalyFetchHddSum(string $start, string $end, float $lat, float $lon): ?float
{
    $cacheDir  = rtrim(Config::cacheDir(), '/\\');
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
    ApiCache::respond($_cacheKey, $json);
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
    ?string $navSub,
    bool $continuousLoad = false   // hus / jordvarme: unusually LOW use is also suspicious
) use ($curDays, $lyDays, $threshCritical, $threshWarning, $threshInfo): ?array {
    if ($lyKwh <= 0) return null;

    $curAvg   = $curKwh / $curDays;
    $lyAvg    = $lyKwh  / $lyDays;

    // A continuous load with no consumption at all means a dead/unsynced meter.
    if ($continuousLoad && $curKwh <= 0) {
        return [
            'category'     => $category,
            'severity'     => 'critical',
            'direction'    => 'none',
            'title'        => $title,
            'headline'     => 'Intet forbrug registreret',
            'message'      => 'Ingen måledata denne måned, mod '
                . number_format($lyAvg, 1, ',', '.') . ' kWh/dag samme periode sidste år. Tjek måler og synkronisering.',
            'pct_above'    => -100,
            'current_avg'  => 0.0,
            'baseline_avg' => round($lyAvg, 2),
            'nav_section'  => $navSection,
            'nav_sub'      => $navSub,
        ];
    }
    if ($curKwh <= 0) return null;

    $pctAbove = (int)round(($curAvg / $lyAvg - 1) * 100);

    // Unusually LOW consumption (continuous loads only; EV charging varies naturally).
    if ($continuousLoad && $pctAbove <= -30) {
        $below = -$pctAbove;
        return [
            'category'     => $category,
            'severity'     => $below >= 50 ? 'warning' : 'info',
            'direction'    => 'low',
            'title'        => $title,
            'headline'     => $below . '% under baseline',
            'message'      => 'Dagligt gennemsnit '
                . number_format($curAvg, 1, ',', '.')
                . ' kWh — ' . $below . '% under samme periode sidste år ('
                . number_format($lyAvg, 1, ',', '.') . ' kWh/dag).',
            'pct_above'    => $pctAbove,
            'current_avg'  => round($curAvg, 2),
            'baseline_avg' => round($lyAvg, 2),
            'nav_section'  => $navSection,
            'nav_sub'      => $navSub,
        ];
    }

    if ($pctAbove < $threshInfo) return null;

    $severity = $pctAbove >= $threshCritical ? 'critical'
              : ($pctAbove >= $threshWarning  ? 'warning' : 'info');

    return [
        'category'     => $category,
        'severity'     => $severity,
        'direction'    => 'high',
        'title'        => $title,
        'headline'     => $pctAbove . '% over baseline',
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
    $a = $makeAnomaly('hus', 'Husforbrug', $husCur, $husLy, 'hus-section', 'hus-forbrug', true);
    if ($a) $anomalies[] = $a;
} catch (\Throwable $husEx) {
    error_log('getAnomalyStats hus: ' . $husEx->getMessage());
}

// =========================================================================
// 2. HP — heat pump (powerlogjord)
// =========================================================================
$hpCur = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $curMonthStart, $curMonthEnd));
$hpLy  = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $lyMonthStart, $lyMonthEnd));
$a = $makeAnomaly('hp', 'Jordvarme', $hpCur, $hpLy, 'jordvarme-section', null, true);
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
// Stale meters: latest reading older than 36 h => sync/meter problem
// =========================================================================
foreach ([['powerloghus', 'hus', 'Husmåler', 'hus-section', 'hus-forbrug'],
          ['powerlogjord', 'hp', 'Jordvarmemåler', 'jordvarme-section', null]] as [$tbl, $cat, $label, $navS, $navSub]) {
    try {
        $last = $powerlogDb->query("SELECT MAX(logdate) FROM $tbl")->fetchColumn();
        $lastTs = $last ? strtotime((string) $last) : false;
        if ($lastTs !== false && (time() - $lastTs) > 36 * 3600) {
            $hours = (int) round((time() - $lastTs) / 3600);
            $anomalies[] = [
                'category' => $cat, 'severity' => 'warning', 'direction' => 'stale',
                'title' => $label, 'headline' => 'Ingen nye målinger',
                'message' => "Seneste måling er {$hours} timer gammel ({$last}). Tjek synkroniseringen.",
                'pct_above' => 0, 'current_avg' => 0.0, 'baseline_avg' => 0.0,
                'nav_section' => $navS, 'nav_sub' => $navSub,
            ];
        }
    } catch (\Throwable $e) {
        error_log("getAnomalyStats stale-check {$tbl}: " . $e->getMessage());
    }
}

// =========================================================================
// Sort: critical first, then warning, then info
// =========================================================================
$severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
usort($anomalies, fn($a, $b) => $severityRank[$a['severity']] <=> $severityRank[$b['severity']]);

// =========================================================================
// Possible cause: weather vs. consumption
// =========================================================================
if (!empty($anomalies)) {
    $lat = (float)(Config::weatherLat());
    $lon = (float)(Config::weatherLon());
    if ($lat !== 0.0 || $lon !== 0.0) {
        $hddCur = _anomalyFetchHddSum($curMonthStart, $curMonthEnd, $lat, $lon);
        $hddLy  = _anomalyFetchHddSum($lyMonthStart,  $lyMonthEnd,  $lat, $lon);
        $hddPct = ($hddLy > 0 && $hddCur !== null && $hddLy !== null)
            ? ($hddCur / $hddLy - 1) * 100
            : null;
        foreach ($anomalies as &$anomaly) {
            if (in_array($anomaly['direction'] ?? '', ['none', 'stale'], true)) continue;
            if (($anomaly['direction'] ?? '') === 'low') {
                // milder weather than last year explains lower heating-driven use
                $anomaly['possible_cause'] = ($hddPct !== null && $hddPct <= -25.0) ? 'vejr' : 'forbrug';
                continue;
            }
            $anomaly['possible_cause'] = ($hddPct !== null && $hddPct >= 40.0) ? 'vejr' : 'forbrug';
        }
        unset($anomaly);
    }
}

$json = json_encode(['anomalies' => $anomalies, 'insufficient_data' => false]);
ApiCache::respond($_cacheKey, $json);
