<?php
/**
 * getElspotPrices.php
 *
 * Aggregates Danish electricity price components from Energi Data Service
 * (api.energidataservice.dk) into daily average prices (kr/kWh) for use
 * in heat pump cost estimation on the Jordvarme tab.
 *
 * Price components (matched hour-by-hour):
 *   Spot price   — Elspotprices dataset, hourly DKK/MWh (DK1/DK2)
 *   Systemtarif  — DatahubPricelist, Energinet, Price1–Price24 per hour
 *   Elafgift     — DatahubPricelist, Energinet, Price1–Price24 per hour
 *   Nettarif C   — DatahubPricelist, user's DSO (by GLN), Price1–Price24 per hour
 *   VAT          — 25% applied to all of the above
 *
 * For each day, every available spot hour is paired with the tariff rates for
 * that specific hour (Price{H+1} where H is 0–23). The daily kr/kWh is the
 * mean of all computed hourly costs.
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
define('CACHE_VERSION',    3);      // bump to invalidate all existing cached responses

// All 24 hourly price columns used in DatahubPricelist queries
define('PRICE_COLUMNS', 'Price1,Price2,Price3,Price4,Price5,Price6,Price7,Price8,' .
                        'Price9,Price10,Price11,Price12,Price13,Price14,Price15,Price16,' .
                        'Price17,Price18,Price19,Price20,Price21,Price22,Price23,' .
                        'Price24,ValidFrom,ValidTo');

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

$cacheKey  = md5($start . '|' . $end . '|' . $area . '|' . $gln . '|v' . CACHE_VERSION);
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

// ─── Helper: get hourly tariff from applicable record ────────────────────────
// Records must be sorted ValidFrom DESC.
// hour: 0–23 (DK local time). Returns Price{hour+1} kr/kWh or 0.0.

function tariff_for_hour(array $records, string $date, int $hour): float {
    $col = 'Price' . ($hour + 1);
    foreach ($records as $rec) {
        $from = substr((string)($rec['ValidFrom'] ?? ''), 0, 10);
        $to   = isset($rec['ValidTo']) && $rec['ValidTo'] !== null
                ? substr((string)$rec['ValidTo'], 0, 10)
                : null;
        if ($from <= $date && ($to === null || $to > $date)) {
            // Use the correct hourly price; fall back to Price1 if the column is absent/null
            $val = (isset($rec[$col]) && $rec[$col] !== null && $rec[$col] !== '')
                   ? $rec[$col] : ($rec['Price1'] ?? 0);
            return floatval($val);
        }
    }
    return 0.0;
}

// ─── Helper: daily-average tariff from all available hourly prices ────────────
// Used for the components summary (representative value at start date).

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

// ─── 1. Spot prices (hourly, DKK/MWh → kr/kWh, grouped by day+hour) ─────────

$spotRecords = eds_fetch('Elspotprices', [
    'start'   => $start,
    'end'     => $end . 'T23:59',
    'filter'  => json_encode(['PriceArea' => $area]),
    'columns' => 'HourDK,SpotPriceDKK',
    'limit'   => 0,
]);

// $hoursByDay[date][] = ['h' => 0-23, 's' => spot_kr_kwh]
$hoursByDay = [];
foreach ($spotRecords as $rec) {
    $hourDK = (string)($rec['HourDK'] ?? '');
    $day    = substr($hourDK, 0, 10);
    if ($day === '') continue;
    // HourDK format: "YYYY-MM-DDTHH:MM:SS" or "YYYY-MM-DD HH:MM:SS"
    $hour = (int)substr($hourDK, 11, 2);
    $spot = floatval($rec['SpotPriceDKK'] ?? 0) / 1000.0; // DKK/MWh → kr/kWh
    $hoursByDay[$day][] = ['h' => $hour, 's' => $spot];
}

// ─── 2. Energinet systemtarif (Price1–Price24) ────────────────────────────────

$systemtarifRecords = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => ENERGINET_GLN, 'Note' => 'Systemtarif']),
    'columns' => PRICE_COLUMNS,
    'limit'   => 50,
    'sort'    => 'ValidFrom desc',
]);

// ─── 3. Elafgift (Price1–Price24, rate varies by year) ───────────────────────

$elafgiftRecords = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => ENERGINET_GLN, 'Note' => 'Elafgift']),
    'columns' => PRICE_COLUMNS,
    'limit'   => 50,
    'sort'    => 'ValidFrom desc',
]);

// ─── 4. Nettarif C from user's DSO (Price1–Price24) ──────────────────────────
// Fetch all DatahubPricelist records for this GLN and filter PHP-side for
// notes that start with "Nettarif C" (case-insensitive). This handles
// operator-specific Note variations such as "Nettarif C time", "Nettarif C
// lavlast" etc. that an exact-match API filter would silently miss.

$nettarifAll = eds_fetch('DatahubPricelist', [
    'filter'  => json_encode(['GLN_Number' => $gln]),
    'columns' => 'Note,' . PRICE_COLUMNS,
    'limit'   => 500,
    'sort'    => 'ValidFrom desc',
]);
$nettarifRecords = array_values(array_filter($nettarifAll, fn($r) =>
    stripos($r['Note'] ?? '', 'nettarif c') === 0
));

// ─── 5. Assemble daily records (hour-by-hour cost matching) ─────────────────

$dailyRecords = [];
$cursor       = new DateTime($start);
$endDate      = new DateTime($end);

while ($cursor <= $endDate) {
    $date  = $cursor->format('Y-m-d');
    $hours = $hoursByDay[$date] ?? [];

    if (empty($hours)) {
        $krKwh = 0.0;
    } else {
        $sum = 0.0;
        foreach ($hours as $entry) {
            $h   = $entry['h'];
            $sys = tariff_for_hour($systemtarifRecords, $date, $h);
            $ela = tariff_for_hour($elafgiftRecords,    $date, $h);
            $net = tariff_for_hour($nettarifRecords,    $date, $h);
            $sum += ($entry['s'] + $sys + $ela + $net) * VAT_FACTOR;
        }
        $krKwh = $sum / count($hours);
    }

    $dailyRecords[] = [
        'date'   => $date,
        'kr_kwh' => round($krKwh, 4),
    ];

    $cursor->modify('+1 day');
}

// ─── 6. Component summary (daily-average values at start date) ────────────────
// spot_avg is the mean of all hourly spot prices on $start;
// tariff averages cover all 24 Price columns of the applicable record.

$startHours   = $hoursByDay[$start] ?? [];
$spotAvgStart = count($startHours) > 0
    ? array_sum(array_column($startHours, 's')) / count($startHours)
    : 0.0;

$components = [
    'spot_avg_kr_kwh'      => round($spotAvgStart, 4),
    'systemtarif_kr_kwh'   => tariff_avg_for_date($systemtarifRecords, $start),
    'elafgift_kr_kwh'      => tariff_avg_for_date($elafgiftRecords,    $start),
    'nettarif_kr_kwh'      => tariff_avg_for_date($nettarifRecords,    $start),
    'nettarif_records'     => count($nettarifRecords),   // 0 = DSO GLN/Note not found
    'spot_hours'           => array_sum(array_map('count', $hoursByDay)),
    'vat_factor'           => VAT_FACTOR,
];

// ─── 7. Cache and respond ────────────────────────────────────────────────────

$response = json_encode([
    'records'    => $dailyRecords,
    'components' => $components,
]);

// Only cache if spot data was found; an empty spot response could be a
// transient API failure that should not be persisted for 6 hours.
if ($components['spot_hours'] > 0) {
    @file_put_contents($cacheFile, $response);
}

echo $response;
?>
