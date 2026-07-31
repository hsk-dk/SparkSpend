<?php
/**
 * getElspotPrices route handler
 *
 * Aggregates Danish electricity price components from Energi Data Service
 * into daily average prices (kr/kWh) for heat pump cost estimation.
 */

define('ENERGINET_GLN',     '5790000432752');
define('EDS_BASE_URL',      'https://api.energidataservice.dk/dataset/');
define('VAT_FACTOR',        1.25);
define('CACHE_TTL_SECONDS', 21600); // 6 hours
define('CACHE_VERSION',    7);
define('ELSPOT_CUTOFF',   '2025-09-30');
define('ELAFGIFT_HP_KR_KWH', 0.0088); // 2025 procesformål rate

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

if ($end < $start) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'end skal være >= start.']);
    exit;
}

// ─── File cache ──────────────────────────────────────────────────────────────

$cacheKey  = md5($start . '|' . $end . '|' . $area . '|' . $gln . '|' . $format . '|v' . CACHE_VERSION);
$_cacheDir = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
$cacheFile = $_cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_' . $cacheKey . '.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL_SECONDS) {
    echo file_get_contents($cacheFile);
    exit;
}

// ─── Helper: fetch from Energi Data Service ──────────────────────────────────

function eds_fetch(string $dataset, array $params): array {
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
        return [];
    }
    $statusLine = $http_response_header[0] ?? 'unknown';
    $data = json_decode($raw, true);
    $count = is_array($data) ? count($data['records'] ?? []) : 0;
    if ($count === 0) {
        error_log("getElspotPrices eds_fetch: 0 records from $dataset — HTTP: $statusLine — body: " . substr($raw, 0, 200));
    }
    return is_array($data) ? ($data['records'] ?? []) : [];
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

// ─── 1. Spot prices ─────────────────────────────────────────────────────────

$hoursByDay = [];

// 1a. Legacy: Elspotprices
if ($start <= ELSPOT_CUTOFF) {
    $elspotEnd   = min($end, ELSPOT_CUTOFF);
    $spotRecords = eds_fetch('Elspotprices', [
        'start'   => $start,
        'end'     => $elspotEnd . 'T23:59',
        'filter'  => json_encode(['PriceArea' => $area]),
        'columns' => 'HourDK,SpotPriceDKK',
        'limit'   => 0,
    ]);
    foreach ($spotRecords as $rec) {
        $hourDK = (string)($rec['HourDK'] ?? '');
        $day    = substr($hourDK, 0, 10);
        if ($day === '') continue;
        $hour = (int)substr($hourDK, 11, 2);
        $spot = floatval($rec['SpotPriceDKK'] ?? 0) / 1000.0;
        $hoursByDay[$day][] = ['h' => $hour, 's' => $spot];
    }
}

// 1b. Current: DayAheadPrices
$daStart = max($start, '2025-10-01');
if ($daStart <= $end) {
    $dayAheadRecords = eds_fetch('DayAheadPrices', [
        'start'   => $daStart,
        'end'     => $end . 'T23:59',
        'filter'  => json_encode(['PriceArea' => $area]),
        'columns' => 'TimeDK,DayAheadPriceDKK',
        'limit'   => 0,
    ]);
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

// ─── 2. Energinet systemtarif ────────────────────────────────────────────────

$systemtarifRecords = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => ENERGINET_GLN, 'Note' => 'Systemtarif']),
    'columns' => PRICE_COLUMNS,
    'limit'   => 50,
    'sort'    => 'ValidFrom desc',
]);

// ─── 3. Residential nettarif from user's DSO ────────────────────────────────

$nettarifAll = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => $gln]),
    'columns' => 'Note,' . PRICE_COLUMNS,
    'end'     => $end,
    'limit'   => 500,
    'sort'    => 'ValidFrom desc',
]);

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
        $sum = 0.0;
        foreach ($hours as $entry) {
            $h         = $entry['h'];
            $sys       = tariff_for_hour($systemtarifRecords, $date, $h);
            $ela       = ELAFGIFT_HP_KR_KWH;
            $net       = tariff_for_hour($nettarifRecords,    $date, $h);
            $hourKrKwh = ($entry['s'] + $sys + $ela + $net) * VAT_FACTOR;
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
    'elafgift_kr_kwh'      => ELAFGIFT_HP_KR_KWH,
    'nettarif_kr_kwh'      => tariff_avg_for_date($nettarifRecords,    $start),
    'nettarif_records'     => count($nettarifRecords),
    'spot_hours'           => array_sum(array_map('count', $hoursByDay)),
    'vat_factor'           => VAT_FACTOR,
];

// ─── 6. Cache and respond ────────────────────────────────────────────────────

$response = json_encode([
    'records'    => $format === 'hourly' ? $hourlyRecords : $dailyRecords,
    'components' => $components,
]);

if ($components['spot_hours'] > 0) {
    @file_put_contents($cacheFile, $response);
} else {
    error_log("getElspotPrices: spot_hours=0 for area=$area start=$start end=$end — skipping cache write.");
}

echo $response;
