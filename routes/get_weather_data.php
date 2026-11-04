<?php
/**
 * getWeatherData route handler
 *
 * Fetches daily mean temperature from the Open-Meteo archive API and
 * converts it to Heating Degree Days.
 *
 * GET params:
 *   start  YYYY-MM-DD
 *   end    YYYY-MM-DD
 *   lat    float (-90 to 90)
 *   lon    float (-180 to 180)
 */

define('OPEN_METEO_URL',    'https://archive-api.open-meteo.com/v1/archive');
define('HDD_BASE_TEMP',     17.0);
define('CACHE_TTL_SECONDS', 86400);  // 24 hours
define('CACHE_VERSION',     1);

// ─── Input validation ────────────────────────────────────────────────────────

$start = isset($_GET['start']) ? trim($_GET['start']) : '';
$end   = isset($_GET['end'])   ? trim($_GET['end'])   : '';
$lat   = isset($_GET['lat'])   ? trim($_GET['lat'])   : '';
$lon   = isset($_GET['lon'])   ? trim($_GET['lon'])   : '';

if (!Http::isDate($start) ||
    !Http::isDate($end)   ||
    !is_numeric($lat) || (float)$lat < -90  || (float)$lat > 90  ||
    !is_numeric($lon) || (float)$lon < -180 || (float)$lon > 180) {
    Http::fail('Ugyldige parametre. Forventet: start, end (YYYY-MM-DD), lat (-90–90), lon (-180–180).');
}

if ($end < $start) {
    Http::fail('end skal være >= start.');
}
if ((strtotime($end) - strtotime($start)) / 86400 > 7300) {
    Http::fail('Perioden må højst være 20 år.');
}

if (Config::weatherLat() !== '' && Config::weatherLon() !== '' &&
    (abs((float)$lat - (float)Config::weatherLat()) > 0.0001 || abs((float)$lon - (float)Config::weatherLon()) > 0.0001)) {
    Http::fail('Ukendte koordinater.');
}

$lat = (float)$lat;
$lon = (float)$lon;

// ─── File cache ──────────────────────────────────────────────────────────────

$cacheKey  = md5($start . '|' . $end . '|' . round($lat, 4) . '|' . round($lon, 4) . '|v' . CACHE_VERSION);
$_cacheDir = Config::cacheDir();
$cacheFile = $_cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_weather_' . $cacheKey . '.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL_SECONDS) {
    echo file_get_contents($cacheFile);
    exit;
}

// ─── Fetch from Open-Meteo ───────────────────────────────────────────────────

$url = OPEN_METEO_URL . '?' . http_build_query([
    'latitude'   => $lat,
    'longitude'  => $lon,
    'start_date' => $start,
    'end_date'   => $end,
    'daily'      => 'temperature_2m_mean',
    'timezone'   => 'Europe/Copenhagen',
]);

$ctx = stream_context_create([
    'http' => [
        'method'        => 'GET',
        'timeout'       => 15,
        'ignore_errors' => true,
        'header'        => "Accept: application/json\r\n",
    ]
]);

$raw = file_get_contents($url, false, $ctx);
if ($raw === false) {
    error_log('getWeatherData: connection failed (allow_url_fopen=' . ini_get('allow_url_fopen') . ')');
    Http::fail('Kunne ikke forbinde til Open-Meteo.', 502);
}

$statusLine = $http_response_header[0] ?? 'unknown';
$data       = json_decode($raw, true);

if (!is_array($data) || !isset($data['daily']['time'])) {
    error_log("getWeatherData: unexpected response — HTTP: $statusLine — body: " . substr($raw, 0, 200));
    Http::fail('Uventet svar fra Open-Meteo.', 502);
}

// ─── Calculate HDD per day ───────────────────────────────────────────────────

$times   = $data['daily']['time']                ?? [];
$temps   = $data['daily']['temperature_2m_mean'] ?? [];
$records = [];

foreach ($times as $i => $date) {
    $t = $temps[$i] ?? null;
    if ($t === null) continue;
    $records[] = [
        'date'        => $date,
        'hdd'         => round(max(0.0, HDD_BASE_TEMP - (float)$t), 2),
        'mean_temp_c' => round((float)$t, 1),
    ];
}

$response = json_encode(['records' => $records]);

@file_put_contents($cacheFile, $response);

echo $response;
