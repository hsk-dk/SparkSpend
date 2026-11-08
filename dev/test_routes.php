<?php
/**
 * Route smoke tests: starts the real application (php -S) on a freshly seeded temporary
 * database (dev/seed_dev.php, synthetic data, MOCK_EDS) and calls every API route over HTTP,
 * session + CSRF included.
 *
 *   php dev/test_routes.php                  # run all checks
 *   php dev/test_routes.php --record F.json  # also write a fingerprint of every GET answer
 *   php dev/test_routes.php --compare F.json # compare the GET answers with a recording
 *   --template DIR   reuse one seeded database (SEED_DIR=DIR php dev/seed_dev.php) for every run.
 *                    Needed for --record/--compare: a fresh seed has different timestamps each time.
 *
 * The recording/compare mode exists to prove that a refactoring changed no output: record
 * before, change code, compare after (back to back - some figures depend on the hour).
 * The weather route needs the internet and is only checked for a sane status.
 */
$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$recordFile  = ($i = array_search('--record', $args, true))  !== false ? ($args[$i + 1] ?? null) : null;
$compareFile = ($i = array_search('--compare', $args, true)) !== false ? ($args[$i + 1] ?? null) : null;
$templateDir = ($i = array_search('--template', $args, true)) !== false ? ($args[$i + 1] ?? null) : null;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}

// ── Temporary instance ───────────────────────────────────────────────────────
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_routes_' . getmypid();
@mkdir($tmp . '/cache', 0777, true);
$envFile = $tmp . '/test.env';
file_put_contents($envFile, implode("\n", [
    "CHARGING_DB_PATH=$tmp/charging_data.db", "POWERLOG_DB_PATH=$tmp/powerlog_data.db",
    "CACHE_DIR=$tmp/cache", "LOG_PATH=$tmp/php_errors.log",
    'VEHICLE_API_KEY=test-key', 'HEALTH_KEY=test-health', 'ADMIN_KEY=test-admin', 'ELSPOT_AREA=DK2', 'ELSPOT_GLN=5790000705689',
    'WEATHER_LAT=55.68', 'WEATHER_LON=12.57', 'SUBSCRIPTION_PER_DAY=3.5', 'TIMEZONE=Europe/Copenhagen',
    'DEBUG=false', 'AUTO_MIGRATE=true', 'DEV_INSECURE_COOKIE=true', 'MOCK_EDS=true', '',
]));
putenv("SPARKSPEND_ENV_FILE=$envFile");
if ($templateDir) {
    // a copy per run: the mutation tests below change the data
    foreach (['charging_data.db', 'powerlog_data.db'] as $f) {
        if (!is_file("$templateDir/$f") || !copy("$templateDir/$f", "$tmp/$f")) { echo "template DIR/$f missing - seed it first
"; exit(1); }
    }
} else {
    putenv("SEED_DIR=$tmp");
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/dev/seed_dev.php") . ' 2>&1', $seedOut, $seedCode);
    if ($seedCode !== 0) { echo implode("
", $seedOut) . "
seeding failed
"; exit(1); }
}

$port = random_int(20000, 40000);
$cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root];
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server.log", 'w'], 2 => ['file', "$tmp/server.log", 'a']], $pipes, $root,
    array_merge(getenv(), ['SPARKSPEND_ENV_FILE' => $envFile]));
register_shutdown_function(function () use ($proc, $tmp) {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    // best-effort cleanup
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
});
$ready = false;
for ($t = 0; $t < 100; $t++) {
    $s = @fsockopen('127.0.0.1', $port, $e, $m, 0.2);
    if ($s) { fclose($s); $ready = true; break; }
    usleep(100000);
}
if (!$ready) { echo "server did not start\n"; exit(1); }

// ── HTTP client with a cookie jar ─────────────────────────────────────────────
$cookies = [];
function http(string $method, string $path, ?string $body = null, array $headers = [], bool $useCookies = true): array {
    global $port, $cookies;
    if ($useCookies && $cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies));
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
        'ignore_errors' => true, 'timeout' => 60,
    ]]);
    $raw = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $status = 0; $hdrs = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int) $m[1];
        elseif ($useCookies && stripos($h, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) $cookies[trim($m[1])] = $m[2];
        elseif (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $hdrs[strtolower(trim($k))] = trim($v); }
    }
    return ['status' => $status, 'headers' => $hdrs, 'body' => (string) $raw, 'json' => json_decode((string) $raw, true)];
}

$page = http('GET', '/index.php');
preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $cm);
$csrf = $cm[1] ?? '';
check('index.php renders and issues a CSRF token', $page['status'] === 200 && strlen($csrf) === 64);

$api = fn(string $q, array $h = []) => http('GET', '/api.php?' . $q, null, $h);
$post = fn(string $action, array $data, bool $withCsrf = true) =>
    http('POST', '/api.php?action=' . $action, json_encode($data), array_merge(['Content-Type: application/json'], $withCsrf ? ["X-CSRF-Token: $csrf"] : []));

// ── Content-Security-Policy: no inline scripts, no inline handlers ────────────────────────
echo "CSP\n";
$csp = $page['headers']['content-security-policy'] ?? '';
preg_match("/script-src([^;]*)/", $csp, $sm);
check("script-src has no 'unsafe-inline' / 'unsafe-eval'", $sm && !str_contains($sm[1], 'unsafe-inline') && !str_contains($sm[1], 'unsafe-eval'), $csp);
check("object-src 'none', base-uri and frame-ancestors are restricted", str_contains($csp, "object-src 'none'") && str_contains($csp, "base-uri 'self'") && str_contains($csp, "frame-ancestors 'none'"));
preg_match_all('#<script\b([^>]*)>#i', $page['body'], $tags);
$inline = array_filter($tags[1], fn($attrs) => !preg_match('/\bsrc=/i', $attrs) && !preg_match('/type="application\/json"/i', $attrs));
check('index.php has no inline <script> (only src= and JSON data blocks)', !$inline, json_encode(array_values($inline)));
$markup = preg_replace('#<script\b.*?</script>#is', '', $page['body']);
check('index.php has no inline event-handler attributes', !preg_match('/\son[a-z]+\s*=\s*["\']/i', $markup));
$jsHandlers = [];
foreach (array_merge(glob($root . '/includes/*.js'), glob($root . '/includes/elbil/*.js')) as $f) {
    if (preg_match('/\bon(click|change|input|submit|load|error|keydown|keyup|mouse\w+)\s*=\s*["\']/i', file_get_contents($f), $m)) $jsHandlers[] = basename($f) . ': ' . $m[0];
}
check('no JS file builds inline event-handler attributes', !$jsHandlers, implode('; ', $jsHandlers));

// ── GET routes: status, JSON, and a few structural keys ───────────────────────
$month = date('Y-m'); $year = date('Y'); $today = date('Y-m-d'); $from = date('Y-m-01');
$gln = '5790000705689';
$gets = [
    'dashboard'            => ['action=dashboard', ['ev', 'heatpump', 'rest', 'cost']],
    'charges (all)'        => ['action=charges&filter=all&showZeroKwh=true', null],
    'charges (vehicle+dates)' => ["action=charges&filter=1&dateRange=$from+til+$today", null],
    'charge-analytics'     => ['action=charge-analytics&filter=all&groupBy=month', ['trend', 'statistics', 'meta']],
    'charge-analytics (zero kWh)' => ['action=charge-analytics&filter=all&showZeroKwh=true&groupBy=month', ['trend', 'statistics', 'meta']],
    'charge-compare'       => ['action=charge-compare', null],
    'charge-optimization'  => ['action=charge-optimization&months=3', ['available', 'totals', 'charges', 'months', 'params']],
    'annual'               => ['action=annual', ['years']],
    'anomalies'            => ['action=anomalies', ['anomalies', 'insufficient_data']],
    'efficiency'           => ['action=efficiency', ['vehicles', 'series']],
    'elspot-prices daily'  => ["action=elspot-prices&start=$from&end=$today&area=DK2&gln=$gln&tariff=low", ['records', 'components']],
    'elspot-prices hourly' => ["action=elspot-prices&start=$today&end=$today&area=DK2&gln=$gln&format=hourly&tariff=high", ['records', 'components']],
    'heatpump daily'       => ["action=heatpump&mode=daily&month=$month", null],
    'heatpump monthly'     => ["action=heatpump&mode=monthly&year=$year", null],
    'heatpump compare'     => ['action=heatpump&mode=compare', null],
    'base-load'            => ['action=base-load&months=12', ['months', 'latest', 'params']],
    'hourly-consumption'   => ["action=hourly-consumption&start=$from&end=$today", ['hours', 'vp', 'rest']],
    'charger-sessions'     => ['action=charger-sessions&days=30', ['sessions']],
    'house-power daily'    => ["action=house-power&mode=daily&month=$month", ['days', 'cost']],
    'house-power monthly'  => ["action=house-power&mode=monthly&year=$year", ['months', 'cost']],
    'house-power compare'  => ['action=house-power&mode=compare', ['data']],
    'house-power ytd'      => ['action=house-power&mode=ytd', ['data']],
    'monthly-bill'         => ['action=monthly-bill&months=12', ['months', 'cost']],
    'providers'            => ['action=providers', null],
    'provider-stats'       => ['action=provider-stats&filter=all', null],
    'sync-status'          => ['action=sync-status', ['sources']],
    'vehicle-comparison'   => ['action=vehicle-comparison', ['vehicles']],
    'vehicles'             => ['action=vehicles', null],
];
$fingerprints = [];
// Routes whose VALUES depend on the time of day (projections use the elapsed part of today):
// only their structure is fingerprinted, or two runs a minute apart would never match.
$timeDependent = ['action=dashboard', 'action=sync-status'];   // sync-status: ages (age_sec) change every second
$shape = function ($v) use (&$shape) {
    if (is_array($v)) { $o = []; foreach ($v as $k => $x) $o[$k] = $shape($x); return $o; }
    return gettype($v) === 'integer' || gettype($v) === 'double' ? 'number' : gettype($v);
};
echo "GET routes\n";
foreach ($gets as $name => [$q, $keys]) {
    $r = $api($q);
    $ok = $r['status'] === 200 && $r['json'] !== null;
    if ($ok && $keys) foreach ($keys as $k) if (!is_array($r['json']) || !array_key_exists($k, $r['json'])) { $ok = false; $name .= " (missing '$k')"; }
    check($name, $ok, "status {$r['status']} " . substr($r['body'], 0, 120));
    $fingerprints[$q] = $r['status'] . ':' . sha1(in_array($q, $timeDependent, true) ? json_encode($shape($r['json'])) : $r['body']);
}
$w = $api("action=weather&start=$from&end=$today&lat=55.68&lon=12.57");
check('weather (needs the internet: 200 or 502)', in_array($w['status'], [200, 502], true), "status {$w['status']}");
$log = $api('action=system-log&lines=5', ["X-CSRF-Token: $csrf"]);
check('system-log with the session CSRF token', $log['status'] === 200 && isset($log['json']['lines']));
check('system-log without credentials is refused', http('GET', '/api.php?action=system-log', null, [], false)['status'] === 403);

// ── Router behaviour ─────────────────────────────────────────────────────────────
echo "router\n";
$r = $api('action=does-not-exist');
check('unknown action -> 404 JSON', $r['status'] === 404 && ($r['json']['success'] ?? null) === false);
check('mutation route called with GET -> refused (405)', $api('action=delete-provider&id=1')['status'] === 405);
check('read route called with POST -> refused (405)', http('POST', '/api.php?action=vehicles', '{}', ["X-CSRF-Token: $csrf"])['status'] === 405);

// ── Mutations (CSRF) ─────────────────────────────────────────────────────────────
echo "mutations\n";
check('POST without CSRF token -> 403', $post('create-provider', ['name' => 'X'], false)['status'] === 403);
$r = $post('create-provider', ['name' => 'Test udbyder']);
$pid = $r['json']['id'] ?? 0;
check('create-provider', $r['status'] === 200 && $pid > 0, $r['body']);
check('create-provider rejects an empty name', $post('create-provider', ['name' => '  '])['status'] === 400);
check('update-provider', $post('update-provider', ['id' => $pid, 'name' => 'Omdøbt'])['status'] === 200);
check('update-provider rejects a bad id', $post('update-provider', ['id' => 0, 'name' => 'x'])['status'] === 400);
check('update-vehicle', $post('update-vehicle', ['id' => 1, 'name' => 'Testbil A'])['status'] === 200);
check('update-vehicle unknown id -> 404', $post('update-vehicle', ['id' => 999, 'name' => 'x'])['status'] === 404);

// External charges never pass the house meter: they must not change the house split (EV / rest) or the bill
$homeEv = function () use ($api, $month, $year): array {
    $hp = $api("action=house-power&mode=monthly&year=$year")['json']['months'] ?? [];
    $cur = array_values(array_filter($hp, fn($m) => $m['month'] === $month))[0] ?? null;
    $bill = $api('action=monthly-bill&months=2')['json']['ev'][$month] ?? null;
    return [$cur['ev'] ?? null, $cur['rest'] ?? null, $bill['kwh'] ?? null, $bill['cost'] ?? null];
};
$evBefore = $homeEv();
$r = $post('create-ext-charge', ['vehicleId' => 1, 'providerId' => $pid, 'chargeDateTime' => date('Y-m-d\TH:i'), 'kwh' => 12.5, 'pris' => 45.0]);
$eid = $r['json']['id'] ?? 0;
check('create-ext-charge', $r['status'] === 201 && $eid > 0, $r['body']);
check('create-ext-charge validates kwh', $post('create-ext-charge', ['vehicleId' => 1, 'providerId' => $pid, 'chargeDateTime' => $today, 'kwh' => 0, 'pris' => 1])['status'] === 400);
check('update-ext-charge', $post('update-ext-charge', ['id' => $eid, 'vehicleId' => 1, 'providerId' => $pid, 'datetime' => date('Y-m-d\TH:i'), 'kwh' => 20, 'pris' => 60])['status'] === 200);
check('update-ext-charge unknown vehicle -> 422', $post('update-ext-charge', ['id' => $eid, 'vehicleId' => 999, 'providerId' => $pid, 'datetime' => $today, 'kwh' => 20, 'pris' => 60])['status'] === 422);
check('an external charge does not change the house split or the bill (home charging only)', $evBefore[0] !== null && $evBefore === $homeEv(), json_encode([$evBefore, $homeEv()]));

$charges = $api('action=charges&filter=all&showZeroKwh=true')['json'] ?? [];
$internal = null; foreach ($charges as $c) if (($c['source'] ?? '') === 'internal') { $internal = $c; break; }
check('update-internal-charge', $internal && $post('update-internal-charge', ['id' => $internal['id'], 'vehicleId' => 2])['status'] === 200);
check('update-internal-charge unknown vehicle -> 422', $internal && $post('update-internal-charge', ['id' => $internal['id'], 'vehicleId' => 999])['status'] === 422);
check('delete-provider in use -> 409', $post('delete-provider', ['id' => $pid])['status'] === 409);
check('delete-ext-charge', $post('delete-ext-charge', ['id' => $eid])['status'] === 200);
check('delete-ext-charge twice -> 404', $post('delete-ext-charge', ['id' => $eid])['status'] === 404);
check('delete-provider', $post('delete-provider', ['id' => $pid])['status'] === 200);
check('trigger-sync rejects an unknown source', $post('trigger-sync', ['source' => 'bogus'])['status'] === 400);

// ── Machine endpoints (Bearer key, no session) ───────────────────────────────────────────────
echo "machine endpoints\n";
$bearer = fn(string $path, array $data, string $key) => http('POST', $path, json_encode($data), ['Content-Type: application/json', "Authorization: Bearer $key"], false);
check('receive_vehicle_data without key -> 401', http('POST', '/receive_vehicle_data.php', '{}', [], false)['status'] === 401);
check('receive_vehicle_data with a wrong key -> 401', $bearer('/receive_vehicle_data.php', [], 'nope')['status'] === 401);
check('receive_vehicle_data stores a reading', $bearer('/receive_vehicle_data.php', ['vehicleId' => 1, 'odometer' => 31000], 'test-key')['status'] === 201);
check('receive_vehicle_data validates the odometer', $bearer('/receive_vehicle_data.php', ['vehicleId' => 1, 'odometer' => -5], 'test-key')['status'] === 400);
check('api.php receive-vehicle-data (same handler) requires the key', http('POST', '/api.php?action=receive-vehicle-data', '{}', [], false)['status'] === 401);
$ev = ['event' => 'plugged', 'eventAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'status' => 'awaiting_authorization'];
check('receive_charger_event without key -> 401', http('POST', '/receive_charger_event.php', json_encode($ev), [], false)['status'] === 401);
check('receive_charger_event stores an event', $bearer('/receive_charger_event.php', $ev, 'test-key')['status'] === 201);
check('receive_charger_event is idempotent', $bearer('/receive_charger_event.php', $ev, 'test-key')['status'] === 200);
$de = ['date' => date('Y-m-d', strtotime('-1 day')), 'devices' => ['varme_skur' => 4.25]];
check('receive_device_energy without key -> 401', http('POST', '/receive_device_energy.php', json_encode($de), [], false)['status'] === 401);
check('receive_device_energy stores a day', $bearer('/receive_device_energy.php', $de, 'test-key')['status'] === 201);
check('receive_device_energy again replaces it (still 201)', $bearer('/receive_device_energy.php', $de, 'test-key')['status'] === 201);
check('receive_device_energy refuses a bad payload', $bearer('/receive_device_energy.php', ['date' => 'x', 'devices' => ['varme_skur' => 1]], 'test-key')['status'] === 400);
$skurMonth = $api('action=monthly-bill&months=2')['json']['skur'][substr($de['date'], 0, 7)] ?? null;
check('the shed heater shows up in the monthly bill (part of rest)', $skurMonth !== null && $skurMonth >= 4.25, json_encode($skurMonth));
$hpMonth = array_values(array_filter($api('action=house-power&mode=monthly&year=' . substr($de['date'], 0, 4))['json']['months'] ?? [], fn($m) => $m['month'] === substr($de['date'], 0, 7)))[0] ?? null;
check('...and in the house-power split', $hpMonth !== null && ($hpMonth['skur'] ?? 0) >= 4.25, json_encode($hpMonth));

$getH = fn(string $key) => http('GET', '/health.php', '', $key === '' ? [] : ["Authorization: Bearer $key"], false);
check('health without key -> 401', $getH('')['status'] === 401);
check('health with the vehicle key (a different secret) -> 401', $getH('test-key')['status'] === 401);
$h = $getH('test-health');
$hj = json_decode($h['body'], true);
check('health answers 200 (ok) or 503 (degraded) and the body agrees', in_array($h['status'], [200, 503], true) && ($hj['status'] ?? '') === ($h['status'] === 200 ? 'ok' : 'degraded'), $h['body']);
check('health lists the checks with a status each', !empty($hj['checks']) && !array_diff(array_column($hj['checks'], 'id'), array_column($hj['checks'], 'id')) && isset($hj['checks'][0]['status']));
check('health refuses POST', http('POST', '/health.php', '{}', ["Authorization: Bearer test-health"], false)['status'] === 405);

// ── Recording / comparison ───────────────────────────────────────────────────────────────────
if ($recordFile) { file_put_contents($recordFile, json_encode($fingerprints, JSON_PRETTY_PRINT)); echo "recorded " . count($fingerprints) . " fingerprints in $recordFile\n"; }
if ($compareFile) {
    $gold = json_decode((string) @file_get_contents($compareFile), true) ?: [];
    $diff = [];
    foreach ($gold as $q => $fp) if (($fingerprints[$q] ?? null) !== $fp) $diff[] = $q;
    check('GET answers identical to the recording (' . count($gold) . ' routes)', !$diff, 'differs: ' . implode(', ', $diff));
}

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
