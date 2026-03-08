<?php
/**
 * getElspotPrices.php
 *
 * Aggregates Danish electricity price components from Energi Data Service
 * (api.energidataservice.dk) into daily average prices (kr/kWh) for use
 * in heat pump cost estimation on the Jordvarme tab.
 *
 * Price components:
 *   Spot price   — Elspotprices dataset, hourly, averaged per day (DK1/DK2)
 *   Systemtarif  — DatahubPricelist, Energinet, period-based flat rate
 *   Elafgift     — DatahubPricelist, Energinet, period-based flat rate
 *   Nettarif C   — DatahubPricelist, user's DSO (by GLN), period-based flat rate
 *   VAT          — 25% applied to all of the above
 *
 * GET params:
 *   start  YYYY-MM-DD   Start of date range (inclusive)
 *   end    YYYY-MM-DD   End of date range (inclusive)
 *   area   DK1 | DK2   Electricity price area
 *   gln    digits only  Grid operator GLN number
 *
 * Results are cached in the system temp directory for 6 hours.
 */

define('ENERGINET_GLN',     '5790000432752');
define('EDS_BASE_URL',      'https://api.energidataservice.dk/dataset/');
define('VAT_FACTOR',        1.25);
define('CACHE_TTL_SECONDS', 21600); // 6 hours

header('Content-Type: application/json');

// ─── Input validation ────────────────────────────────────────────────────────

$start = isset($_GET['start']) ? trim($_GET['start']) : '';
$end   = isset($_GET['end'])   ? trim($_GET['end'])   : '';
$area  = isset($_GET['area'])  ? trim($_GET['area'])  : '';
$gln   = isset($_GET['gln'])   ? trim($_GET['gln'])   : '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)   ||
    !in_array($area, ['DK1', 'DK2'], true)        ||
    !preg_match('/^\d+$/', $gln)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ugyldige parametre. Forventet: start, end (YYYY-MM-DD), area (DK1|DK2), gln (tal).']);
    exit;
}

if ($end < $start) {
    http_response_code(400);
    echo json_encode(['error' => 'end skal være >= start.']);
    exit;
}

// ─── File cache ──────────────────────────────────────────────────────────────

$cacheKey  = md5($start . '|' . $end . '|' . $area . '|' . $gln);
$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_' . $cacheKey . '.json';

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
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        error_log("getElspotPrices: failed to fetch $url");
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? ($data['records'] ?? []) : [];
}

// ─── Helper: find applicable tariff for a given date ─────────────────────────
// Records must be sorted ValidFrom DESC. Returns Price1 (kr/kWh) or 0.0.

function tariff_for_date(array $records, string $date): float {
    foreach ($records as $rec) {
        $from = substr((string)($rec['ValidFrom'] ?? ''), 0, 10);
        $to   = isset($rec['ValidTo']) && $rec['ValidTo'] !== null
                ? substr((string)$rec['ValidTo'], 0, 10)
                : null;
        if ($from <= $date && ($to === null || $to > $date)) {
            return floatval($rec['Price1'] ?? 0);
        }
    }
    return 0.0;
}

// ─── 1. Spot prices (hourly → daily average, DKK/MWh ÷ 1000 = kr/kWh) ──────

$spotRecords = eds_fetch('Elspotprices', [
    'start'   => $start,
    'end'     => $end . 'T23:59',
    'filter'  => json_encode(['PriceArea' => $area]),
    'columns' => 'HourDK,SpotPriceDKK',
    'limit'   => 0,
]);

$spotByDay = [];
foreach ($spotRecords as $rec) {
    $day = substr((string)($rec['HourDK'] ?? ''), 0, 10);
    if ($day === '') continue;
    $price = floatval($rec['SpotPriceDKK'] ?? 0);
    if (!isset($spotByDay[$day])) $spotByDay[$day] = ['sum' => 0.0, 'cnt' => 0];
    $spotByDay[$day]['sum'] += $price;
    $spotByDay[$day]['cnt'] += 1;
}

// ─── 2. Energinet systemtarif ────────────────────────────────────────────────

$systemtarifRecords = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => ENERGINET_GLN, 'Note' => 'Systemtarif']),
    'columns' => 'Price1,ValidFrom,ValidTo',
    'limit'   => 50,
    'sort'    => 'ValidFrom desc',
]);

// ─── 3. Elafgift (fetched dynamically — rate varies by year) ─────────────────

$elafgiftRecords = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => ENERGINET_GLN, 'Note' => 'Elafgift']),
    'columns' => 'Price1,ValidFrom,ValidTo',
    'limit'   => 50,
    'sort'    => 'ValidFrom desc',
]);

// ─── 4. Nettarif C from user's DSO ───────────────────────────────────────────

$nettarifRecords = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => $gln, 'Note' => 'Nettarif C']),
    'columns' => 'Price1,ValidFrom,ValidTo',
    'limit'   => 50,
    'sort'    => 'ValidFrom desc',
]);

// ─── 5. Assemble daily records ───────────────────────────────────────────────

$dailyRecords = [];
$cursor       = new DateTime($start);
$endDate      = new DateTime($end);

while ($cursor <= $endDate) {
    $date = $cursor->format('Y-m-d');

    // Spot: avg DKK/MWh for the day, converted to kr/kWh (÷ 1000)
    $spotKrKwh = 0.0;
    if (isset($spotByDay[$date]) && $spotByDay[$date]['cnt'] > 0) {
        $spotKrKwh = ($spotByDay[$date]['sum'] / $spotByDay[$date]['cnt']) / 1000.0;
    }

    $systemtarif = tariff_for_date($systemtarifRecords, $date);
    $elafgift    = tariff_for_date($elafgiftRecords,    $date);
    $nettarif    = tariff_for_date($nettarifRecords,    $date);

    $krKwh = ($spotKrKwh + $systemtarif + $elafgift + $nettarif) * VAT_FACTOR;

    $dailyRecords[] = [
        'date'   => $date,
        'kr_kwh' => round($krKwh, 4),
    ];

    $cursor->modify('+1 day');
}

// ─── 6. Component summary (representative values at start date) ───────────────

$components = [
    'spot_avg_kr_kwh'    => isset($spotByDay[$start]) && $spotByDay[$start]['cnt'] > 0
                                ? round($spotByDay[$start]['sum'] / $spotByDay[$start]['cnt'] / 1000, 4)
                                : 0.0,
    'systemtarif_kr_kwh' => round(tariff_for_date($systemtarifRecords, $start), 4),
    'elafgift_kr_kwh'    => round(tariff_for_date($elafgiftRecords,    $start), 4),
    'nettarif_kr_kwh'    => round(tariff_for_date($nettarifRecords,    $start), 4),
    'vat_factor'         => VAT_FACTOR,
];

// ─── 7. Cache and respond ────────────────────────────────────────────────────

$response = json_encode([
    'records'    => $dailyRecords,
    'components' => $components,
]);

@file_put_contents($cacheFile, $response);

echo $response;
?>
