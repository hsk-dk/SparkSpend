<?php
/**
 * getElspotPrices route handler
 *
 * Aggregates Danish electricity price components from Energi Data Service
 * into daily average prices (kr/kWh) for heat pump cost estimation.
 */

define('EDS_BASE_URL',      'https://api.energidataservice.dk/dataset/');
define('CACHE_TTL_SECONDS', 21600); // 6 hours
define('CACHE_VERSION',    8);      // bumped: elafgift now date-versioned via Config

// Tax/tariff constants — sourced from Config so they can be updated centrally
// and versioned by year (see includes/Config.php). No longer hardcoded here.
$VAT_FACTOR    = Config::vatFactor();
$ENERGINET_GLN = Config::energinetGln();
$ELSPOT_CUTOFF = Config::elspotCutoff();

// All 24 hourly price columns used in DatahubPricelist queries
define('PRICE_COLUMNS', 'Price1,Price2,Price3,Price4,Price5,Price6,Price7,Price8,' .
                        'Price9,Price10,Price11,Price12,Price13,Price14,Price15,Price16,' .
                        'Price17,Price18,Price19,Price20,Price21,Price22,Price23,' .
                        'Price24,ValidFrom,ValidTo');

// ─── Input validation ────────────────────────────────────────────────────────

$start = isset($_GET['start']) ? trim($_GET['start']) : '';
$end   = isset($_GET['end'])   ? trim($_GET['end'])   : '';
$area  = isset($_GET['area'])  ? trim($_GET['area'])  : '';
$gln   = isset($_GET['gln'])   ? trim($_GET['gln'])   : '';
$format = isset($_GET['format']) ? trim($_GET['format']) : 'daily';
// Electricity-tax profile: 'low' = reduced elvarme rate (default; applies to
// EV charging and to VP+Rest above the annual threshold); 'high' = full
// ordinary elafgift (first 4000 kWh/year of VP+Rest). The client blends the
// two using the monthly high-rate kWh threshold returned in components.
$tariff = isset($_GET['tariff']) ? trim($_GET['tariff']) : 'low';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)   ||
    !in_array($area, ['DK1', 'DK2'], true)        ||
    !preg_match('/^\d+$/', $gln)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ugyldige parametre. Forventet: start, end (YYYY-MM-DD), area (DK1|DK2), gln (tal).']);
    exit;
}
if (!in_array($format, ['daily', 'hourly'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'format skal være daily eller hourly.']);
    exit;
}
if (!in_array($tariff, ['high', 'low'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'tariff skal være high eller low.']);
    exit;
}

// Only the configured price area / grid operator is served — arbitrary values
// would each create their own cache file and upstream request.
if (Config::elspotGln() !== '' && $gln !== Config::elspotGln()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ukendt gln.']);
    exit;
}

if ($end < $start) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'end skal være >= start.']);
    exit;
}
if ((strtotime($end) - strtotime($start)) / 86400 > 7300) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Perioden må højst være 20 år.']);
    exit;
}

// ─── File cache ──────────────────────────────────────────────────────────────

$cacheKey  = md5($start . '|' . $end . '|' . $area . '|' . $gln . '|' . $format . '|' . $tariff . '|v' . CACHE_VERSION);
$_cacheDir = Config::cacheDir();
$cacheFile = $_cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_' . $cacheKey . '.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL_SECONDS) {
    echo file_get_contents($cacheFile);
    exit;
}

// ─── Helper: fetch from Energi Data Service ──────────────────────────────────

// Raw upstream responses are cached per dataset+query so that the high/low tariff
// requests, the dashboard forecast and the monthly rate calls share ONE set of
// Energi Data Service calls. Files use the .dat extension on purpose: the sync
// trigger wipes sparkspend_*.json, and prices are independent of meter syncs.
define('EDS_TTL_TARIFF',  86400);        // DatahubPricelist: changes a few times a year
define('EDS_TTL_PAST',    30 * 86400);   // spot prices of finished days never change
define('EDS_TTL_LIVE',    900);          // ranges touching today/tomorrow (15 min)
define('EDS_STALE_MAX',   7 * 86400);    // serve expired data at most this old when upstream is unavailable

function eds_cache_file(string $name): string {
    return Config::cacheDir() . DIRECTORY_SEPARATOR . 'sparkspend_eds_' . $name . '.dat';
}

function eds_read_cache(string $file, int $ttl, bool $allowStale = false): ?array {
    if (!is_file($file)) return null;
    $age = time() - (int) @filemtime($file);
    if ($age >= $ttl && !($allowStale && $age < EDS_STALE_MAX)) return null;
    $json = @file_get_contents($file);
    $rec  = $json !== false ? json_decode($json, true) : null;
    return is_array($rec) ? $rec : null;
}

// Seconds until the rate limit is lifted (0 = not limited).
function eds_backoff_remaining(): int {
    $f = eds_cache_file('backoff');
    $until = is_file($f) ? (int) @file_get_contents($f) : 0;
    return max(0, $until - time());
}

// Returns array of records, or null on rate-limit (429).
// $ttl: how long a cached response stays fresh. On rate-limit / outage an expired
// copy is still used rather than returning nothing.
function eds_fetch(string $dataset, array $params, int $ttl = EDS_TTL_LIVE): ?array {
    // Local test instances (MOCK_EDS=true) use deterministic synthetic data: offline,
    // no rate limit, reproducible numbers. Never enable in production.
    if (env('MOCK_EDS', 'false') === 'true') return eds_mock($dataset, $params);

    $cacheFile = eds_cache_file(md5($dataset . '?' . http_build_query($params)));

    $fresh = eds_read_cache($cacheFile, $ttl);
    if ($fresh !== null) return $fresh;

    // Rate-limited recently: do not call upstream at all.
    $_left = eds_backoff_remaining();
    if ($_left > 0) {
        error_log("getElspotPrices eds_fetch: skipping $dataset — rate-limit pause, {$_left}s left");
        return eds_read_cache($cacheFile, $ttl, true);   // stale copy, or null = "rate-limited"
    }

    $url = EDS_BASE_URL . $dataset . '?' . http_build_query($params);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 30,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\n",
        ]
    ]);
    $raw = file_get_contents($url, false, $ctx);
    if ($raw === false) {
        error_log("getElspotPrices eds_fetch: connection failed for $dataset (allow_url_fopen=" . ini_get('allow_url_fopen') . ")");
        return eds_read_cache($cacheFile, $ttl, true) ?? [];
    }
    $statusLine = $http_response_header[0] ?? 'unknown';

    // Detect rate-limiting — remember it, so no further calls are made until it
    // is lifted, and return null so callers can stop gracefully
    if (str_contains($statusLine, '429')) {
        $wait = preg_match('/in (\d+) seconds/i', $raw, $m) ? (int) $m[1] : 60;
        @file_put_contents(eds_cache_file('backoff'), (string) (time() + max(5, min(600, $wait)) + 2));
        error_log("getElspotPrices eds_fetch: rate-limited (429) for $dataset — backing off {$wait}s");
        return eds_read_cache($cacheFile, $ttl, true);
    }

    $data = json_decode($raw, true);
    $records = is_array($data) ? ($data['records'] ?? []) : [];
    $count = count($records);
    if ($count === 0) {
        error_log("getElspotPrices eds_fetch: 0 records from $dataset — HTTP: $statusLine — body: " . substr($raw, 0, 200));
        return eds_read_cache($cacheFile, $ttl, true) ?? [];   // never cache an empty/failed answer
    }
    if (str_starts_with($statusLine, 'HTTP/') && str_contains($statusLine, ' 200')) {
        @file_put_contents($cacheFile, json_encode($records), LOCK_EX);
    }
    return $records;
}

// Deterministic synthetic Energi Data Service data (see MOCK_EDS above).
function eds_mock(string $dataset, array $params): array {
    if ($dataset === 'DatahubPricelist') {
        $filter = json_decode((string) ($params['filter'] ?? '{}'), true) ?: [];
        $isSystem = ($filter['Note'] ?? '') === 'Systemtarif';
        $rec = ['ValidFrom' => '2020-01-01T00:00:00', 'ValidTo' => null];
        for ($h = 1; $h <= 24; $h++) {
            if ($isSystem) { $rec["Price$h"] = 0.072; continue; }
            $hour = $h - 1;                                   // 0..23
            $rec["Price$h"] = ($hour >= 17 && $hour <= 20) ? 0.85 : (($hour >= 6 && $hour <= 16) ? 0.32 : 0.16);
        }
        if ($isSystem) $rec['Note'] = 'Systemtarif'; else $rec['Note'] = 'Nettarif C';
        return [$rec];
    }
    // Spot datasets: hourly records between start and end (inclusive), DKK/MWh.
    $from = substr((string) ($params['start'] ?? ''), 0, 10);
    $to   = substr((string) ($params['end']   ?? ''), 0, 10);
    if ($from === '' || $to === '' || $to < $from) return [];
    $out = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
        $day = date('Y-m-d', $d);
        $doy = (int) date('z', $d);
        for ($h = 0; $h < 24; $h++) {
            $price = 600 + 350 * sin($doy * 0.37 + $h * M_PI / 12) + 120 * sin($doy / 58.0 * M_PI);   // DKK/MWh
            $t = sprintf('%sT%02d:00:00', $day, $h);
            $out[] = $dataset === 'Elspotprices'
                ? ['HourDK' => $t, 'SpotPriceDKK' => round($price, 2)]
                : ['TimeDK' => $t, 'DayAheadPriceDKK' => round($price, 2)];
        }
    }
    return $out;
}

// Spot-price TTL: finished days are final; ranges reaching today/tomorrow are not.
function eds_spot_ttl(string $chunkEnd): int {
    return $chunkEnd < date('Y-m-d', strtotime('-1 day')) ? EDS_TTL_PAST : EDS_TTL_LIVE;
}

// ─── Helper: get hourly tariff from applicable record ────────────────────────

function tariff_for_hour(array $records, string $date, int $hour): float {
    $col = 'Price' . ($hour + 1);
    foreach ($records as $rec) {
        $from = substr((string)($rec['ValidFrom'] ?? ''), 0, 10);
        $to   = isset($rec['ValidTo']) && $rec['ValidTo'] !== null
                ? substr((string)$rec['ValidTo'], 0, 10)
                : null;
        if ($from <= $date && ($to === null || $to > $date)) {
            $val = (isset($rec[$col]) && $rec[$col] !== null && $rec[$col] !== '')
                   ? $rec[$col] : ($rec['Price1'] ?? 0);
            return floatval($val);
        }
    }
    return 0.0;
}

// ─── Helper: daily-average tariff ────────────────────────────────────────────

function tariff_avg_for_date(array $records, string $date): float {
    foreach ($records as $rec) {
        $from = substr((string)($rec['ValidFrom'] ?? ''), 0, 10);
        $to   = isset($rec['ValidTo']) && $rec['ValidTo'] !== null
                ? substr((string)$rec['ValidTo'], 0, 10)
                : null;
        if ($from <= $date && ($to === null || $to > $date)) {
            $sum = 0.0; $cnt = 0;
            for ($i = 1; $i <= 24; $i++) {
                $v = $rec['Price' . $i] ?? null;
                if ($v !== null && (string)$v !== '') {
                    $sum += floatval($v); $cnt++;
                }
            }
            return $cnt > 0 ? round($sum / $cnt, 4) : 0.0;
        }
    }
    return 0.0;
}

// ─── 1. Spot prices (chunked to avoid EDS timeout on large date ranges) ─────

$hoursByDay = [];

// Helper: split a date range into max N-day chunks
function _dateChunks(string $from, string $to, int $maxDays = 90): array {
    $chunks = [];
    $cursor = $from;
    while ($cursor <= $to) {
        $chunkEnd = date('Y-m-d', min(strtotime($to), strtotime($cursor . " +{$maxDays} days -1 day")));
        $chunks[] = [$cursor, $chunkEnd];
        $cursor = date('Y-m-d', strtotime($chunkEnd . ' +1 day'));
    }
    return $chunks;
}

// 1a. Legacy: Elspotprices (before cutover date)
if ($start <= $ELSPOT_CUTOFF) {
    $elspotEnd = min($end, $ELSPOT_CUTOFF);
    foreach (_dateChunks($start, $elspotEnd) as [$chunkStart, $chunkEnd]) {
        $spotRecords = eds_fetch('Elspotprices', [
            'start'   => $chunkStart,
            'end'     => $chunkEnd . 'T23:59',
            'filter'  => json_encode(['PriceArea' => $area]),
            'columns' => 'HourDK,SpotPriceDKK',
            'limit'   => 0,
        ], eds_spot_ttl($chunkEnd));
        if ($spotRecords === null) break; // rate-limited — use what we have
        foreach ($spotRecords as $rec) {
            $hourDK = (string)($rec['HourDK'] ?? '');
            $day    = substr($hourDK, 0, 10);
            if ($day === '') continue;
            $hour = (int)substr($hourDK, 11, 2);
            $spot = floatval($rec['SpotPriceDKK'] ?? 0) / 1000.0;
            $hoursByDay[$day][] = ['h' => $hour, 's' => $spot];
        }
    }
}

// 1b. Current: DayAheadPrices (from 2025-10-01 onwards)
$daStart = max($start, '2025-10-01');
if ($daStart <= $end) {
    foreach (_dateChunks($daStart, $end) as [$chunkStart, $chunkEnd]) {
        $dayAheadRecords = eds_fetch('DayAheadPrices', [
            'start'   => $chunkStart,
            'end'     => $chunkEnd . 'T23:59',
            'filter'  => json_encode(['PriceArea' => $area]),
            'columns' => 'TimeDK,DayAheadPriceDKK',
            'limit'   => 0,
        ], eds_spot_ttl($chunkEnd));
        if ($dayAheadRecords === null) break; // rate-limited — use what we have
        $hourAccum = [];
        foreach ($dayAheadRecords as $rec) {
            $timeDK = (string)($rec['TimeDK'] ?? '');
            $day    = substr($timeDK, 0, 10);
            if ($day === '') continue;
            $hour = (int)substr($timeDK, 11, 2);
            $spot = floatval($rec['DayAheadPriceDKK'] ?? 0) / 1000.0;
            $hourAccum[$day][$hour]['sum'] = ($hourAccum[$day][$hour]['sum'] ?? 0.0) + $spot;
            $hourAccum[$day][$hour]['cnt'] = ($hourAccum[$day][$hour]['cnt'] ?? 0)   + 1;
        }
        foreach ($hourAccum as $day => $hours) {
            ksort($hours);
            foreach ($hours as $hour => $acc) {
                $hoursByDay[$day][] = ['h' => $hour, 's' => $acc['sum'] / $acc['cnt']];
            }
        }
    }
}

// ─── 2. Energinet systemtarif ────────────────────────────────────────────────

$systemtarifRecords = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => $ENERGINET_GLN, 'Note' => 'Systemtarif']),
    'columns' => PRICE_COLUMNS,
    'limit'   => 50,
    'sort'    => 'ValidFrom desc',
], EDS_TTL_TARIFF) ?? [];

// ─── 3. Residential nettarif from user's DSO ────────────────────────────────

$nettarifAll = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => $gln]),
    'columns' => 'Note,' . PRICE_COLUMNS,
    'end'     => $end,
    'limit'   => 500,
    'sort'    => 'ValidFrom desc',
], EDS_TTL_TARIFF) ?? [];

$netPatterns     = ['nettarif c', 'nettarif a lav', 'nettarif a'];
$nettarifRecords = [];
foreach ($netPatterns as $_pat) {
    $candidates = array_values(array_filter($nettarifAll, fn($r) =>
        stripos($r['Note'] ?? '', $_pat) === 0 &&
        stripos($r['Note'] ?? '', 'samplaceret') === false
    ));
    if ($candidates) { $nettarifRecords = $candidates; break; }
}
if (!$nettarifRecords) {
    foreach ($netPatterns as $_pat) {
        $candidates = array_values(array_filter($nettarifAll, fn($r) =>
            stripos($r['Note'] ?? '', $_pat) === 0
        ));
        if ($candidates) { $nettarifRecords = $candidates; break; }
    }
}

// ─── 4. Assemble daily records ──────────────────────────────────────────────

$dailyRecords  = [];
$hourlyRecords = [];
$cursor        = new DateTime($start);
$endDate       = new DateTime($end);

while ($cursor <= $endDate) {
    $date  = $cursor->format('Y-m-d');
    $hours = $hoursByDay[$date] ?? [];

    if (empty($hours)) {
        $krKwh = 0.0;
    } else {
        // Electricity tax applicable on this date, per the selected profile
        // (year-versioned): full "high" rate vs. reduced "low" elvarme rate.
        $ela = $tariff === 'high'
            ? Config::elafgiftHigh($date)
            : Config::elafgiftLow($date);
        $sum = 0.0;
        foreach ($hours as $entry) {
            $h         = $entry['h'];
            $sys       = tariff_for_hour($systemtarifRecords, $date, $h);
            $net       = tariff_for_hour($nettarifRecords,    $date, $h);
            $hourKrKwh = ($entry['s'] + $sys + $ela + $net) * $VAT_FACTOR;
            $sum      += $hourKrKwh;
            $hourlyRecords[] = ['date' => $date, 'hour' => $entry['h'], 'kr_kwh' => round($hourKrKwh, 4)];
        }
        $krKwh = $sum / count($hours);
    }

    $dailyRecords[] = [
        'date'   => $date,
        'kr_kwh' => round($krKwh, 4),
    ];

    $cursor->modify('+1 day');
}

// ─── 5. Component summary ────────────────────────────────────────────────────

$startHours   = $hoursByDay[$start] ?? [];
$spotAvgStart = count($startHours) > 0
    ? array_sum(array_column($startHours, 's')) / count($startHours)
    : 0.0;

$components = [
    'spot_avg_kr_kwh'      => round($spotAvgStart, 4),
    'systemtarif_kr_kwh'   => tariff_avg_for_date($systemtarifRecords, $start),
    'elafgift_kr_kwh'      => $tariff === 'high'
                                ? Config::elafgiftHigh($start)
                                : Config::elafgiftLow($start),
    'tariff'               => $tariff,
    'high_kwh_per_month'   => Config::elvarmeHighKwhPerMonth(),
    'nettarif_kr_kwh'      => tariff_avg_for_date($nettarifRecords,    $start),
    'nettarif_records'     => count($nettarifRecords),
    'spot_hours'           => array_sum(array_map('count', $hoursByDay)),
    'vat_factor'           => $VAT_FACTOR,
];

// ─── 6. Cache and respond ────────────────────────────────────────────────────

$response = json_encode([
    'records'    => $format === 'hourly' ? $hourlyRecords : $dailyRecords,
    'components' => $components,
]);

// Only cache COMPLETE answers: a response built while a tariff lookup was
// rate-limited (nettarif/systemtarif missing) would otherwise be served with
// wrong prices for the whole TTL. The raw upstream pieces are cached separately.
$complete = $components['spot_hours'] > 0 && !empty($systemtarifRecords) && !empty($nettarifRecords);
if ($complete) {
    @file_put_contents($cacheFile, $response);
} else {
    error_log("getElspotPrices: incomplete (spot_hours={$components['spot_hours']}, system=" . count($systemtarifRecords)
        . ", nettarif=" . count($nettarifRecords) . ") for area=$area start=$start end=$end — skipping cache write.");
}

echo $response;
