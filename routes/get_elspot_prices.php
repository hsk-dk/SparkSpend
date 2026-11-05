<?php
/**
 * getElspotPrices route handler
 *
 * Aggregates Danish electricity price components from Energi Data Service
 * into daily average prices (kr/kWh) for heat pump cost estimation.
 */

define('CACHE_TTL_SECONDS', 21600); // 6 hours
define('CACHE_VERSION',    8);      // bumped: elafgift now date-versioned via Config

require_once __DIR__ . '/../includes/ElspotPrices.php';

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

if (!Http::isDate($start) ||
    !Http::isDate($end)   ||
    !in_array($area, ['DK1', 'DK2'], true)        ||
    !preg_match('/^\d+$/', $gln)) {
    Http::fail('Ugyldige parametre. Forventet: start, end (YYYY-MM-DD), area (DK1|DK2), gln (tal).');
}
if (!in_array($format, ['daily', 'hourly'], true)) {
    Http::fail('format skal være daily eller hourly.');
}
if (!in_array($tariff, ['high', 'low'], true)) {
    Http::fail('tariff skal være high eller low.');
}

// Only the configured price area / grid operator is served — arbitrary values
// would each create their own cache file and upstream request.
if (Config::elspotGln() !== '' && $gln !== Config::elspotGln()) {
    Http::fail('Ukendt gln.');
}

if ($end < $start) {
    Http::fail('end skal være >= start.');
}
if ((strtotime($end) - strtotime($start)) / 86400 > 7300) {
    Http::fail('Perioden må højst være 20 år.');
}

// ─── File cache ──────────────────────────────────────────────────────────────

// The tax rate file's fingerprint is part of the key: editing config/tax_rates.json takes effect at once.
$cacheKey  = md5($start . '|' . $end . '|' . $area . '|' . $gln . '|' . $format . '|' . $tariff . '|v' . CACHE_VERSION . '|t' . Config::taxRatesVersion());
$_cacheDir = Config::cacheDir();
$cacheFile = $_cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_' . $cacheKey . '.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL_SECONDS) {
    echo file_get_contents($cacheFile);
    exit;
}

// ─── Compute (shared with the server-side cost calculations) ────────────────

$result = ElspotPrices::compute($start, $end, $area, $gln, $tariff);
$components = $result['components'];

// ─── Cache and respond ──────────────────────────────────────────────────────

$response = json_encode([
    'records'    => $format === 'hourly' ? $result['hourly'] : $result['daily'],
    'components' => $components,
]);

// Only cache COMPLETE answers: a response built while a tariff lookup was
// rate-limited (nettarif/systemtarif missing) would otherwise be served with
// wrong prices for the whole TTL. The raw upstream pieces are cached separately.
if ($result['complete']) {
    @file_put_contents($cacheFile, $response);
} else {
    error_log("getElspotPrices: incomplete (spot_hours={$components['spot_hours']}, system={$result['diag']['system']}"
        . ", nettarif={$result['diag']['nettarif']}) for area=$area start=$start end=$end — skipping cache write.");
}

echo $response;
