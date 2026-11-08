<?php
/**
 * The route handlers called IN-PROCESS: no web server, no output, no exit. A route file returns a function
 * (Request) -> Response; failures are HttpException. This is what the move from "scripts that echo and exit" bought,
 * and the second half of this test keeps it that way (no route may echo, exit or read $_GET / $_SERVER again).
 * Run:  php dev/test_handlers.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';
require __DIR__ . '/../includes/autoload.php';
Config::load();
define('SPARKSPEND_BOOTED', true);
ob_start();   // the test's own output is held back: in the CLI any output counts as "headers sent", and Router::run sets a status

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
/** Calls a handler; returns [Response|null, HttpException|null] and fails the test if anything was written to the output. */
function call(string $route, Request $req): array {
    ob_start();
    $res = null; $err = null;
    try { $res = Router::handle("routes/$route.php", $req); } catch (HttpException $e) { $err = $e; }
    $out = ob_get_clean();
    check("$route wrote nothing to the output", $out === '', json_encode($out));
    return [$res, $err];
}

// ── a real (temporary) database pair, built by the real migrations ──────────────────────────────
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'handlers_' . getmypid();
@mkdir($dir . '/cache', 0777, true);
foreach (['charging_data.db' => 'charges', 'powerlog_data.db' => 'powerlog'] as $file => $kind) {
    $db = new PDO("sqlite:$dir/$file"); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ob_start(); MigrationRunner::run($db, __DIR__ . "/../migrations/$kind"); ob_end_clean();
}
$seed = new PDO("sqlite:$dir/charging_data.db"); $seed->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seed->exec("INSERT INTO vehicles (id, vehicleName) VALUES (1, 'Enyaq'), (2, 'e-Up')");
$seed->exec("INSERT INTO providers (providerName) VALUES ('Clever')");
$seed = null; unset($db);   // let go of the files: Windows will not delete an open database
Config::override(['dbPath' => "$dir/charging_data.db", 'powerlogDbPath' => "$dir/powerlog_data.db", 'cacheDir' => "$dir/cache",
                  'autoMigrate' => false, 'vehicleApiKey' => 'vehicle-key', 'healthKey' => '', 'adminKey' => 'admin-key']);
DatabaseManager::reset();

echo "Request\n";
$r = Request::make('POST', ['filter' => '  42 '], ['name' => 'x'], ['Authorization' => 'Bearer abc', 'X-CSRF-Token' => 'tok']);
check('method, query and a JSON body', $r->method === 'POST' && $r->query['filter'] === '  42 ' && $r->body() === ['name' => 'x']);
check('param() is trimmed, and \'\' (or the default) when missing', $r->param('filter') === '42' && $r->param('nope') === '' && $r->param('nope', 'd') === 'd');
check('headers by their usual names', $r->header('Authorization') === 'Bearer abc' && $r->header('X-CSRF-Token') === 'tok' && $r->header('X-Nothing') === '');
check('no body is [], and invalid JSON is []', Request::make('GET')->body() === [] && (new Request('POST', [], [], ['CONTENT_TYPE' => 'application/json'], [], '{not json'))->body() === []);
check('a form post is read as the body when the content type is not JSON', (new Request('POST', [], ['a' => '1'], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], [], 'a=1'))->body() === ['a' => '1']);
$threw = null; try { Request::make('POST')->requireBody('nothing sent'); } catch (HttpException $e) { $threw = $e; }
check('requireBody() on an empty body is a 400 with the message', $threw?->status === 400 && $threw->getMessage() === 'nothing sent');

echo "Response / HttpException\n";
$res = Response::json(['a' => 1], 201, ['X-Test' => 'y']);
check('json(): body, status, headers, data()', $res->body === '{"a":1}' && $res->status === 201 && $res->headers === ['X-Test' => 'y'] && $res->data() === ['a' => 1]);
check('a string is taken as JSON already; no status means "leave it"', Response::json('[1,2]')->body === '[1,2]' && Response::json([])->status === null);
$e = new HttpException('Nej', 409, ['Allow' => 'GET']);
check('HttpException carries message, status and headers', $e->getMessage() === 'Nej' && $e->status === 409 && $e->headers === ['Allow' => 'GET']);
$threw = null; try { Http::fail('boom', 422); } catch (HttpException $x) { $threw = $x; }
check('Http::fail() throws instead of exiting', $threw?->status === 422 && $threw->getMessage() === 'boom');

echo "reads\n";
[$res] = call('get_vehicles', Request::make('GET'));
check('get_vehicles returns the vehicles', $res instanceof Response && count($res->data()) === 2 && $res->data()[0]['vehicleName'] === 'Enyaq');
[$res] = call('get_providers', Request::make('GET'));
check('get_providers returns the providers', $res->data()[0]['providerName'] === 'Clever');
[$res] = call('get_charges', Request::make('GET', ['filter' => 'all', 'showZeroKwh' => 'true']));
check('get_charges on an empty table is an empty list', $res->data() === []);

echo "writes and their errors\n";
[$res, $err] = call('create_provider', Request::make('POST', [], ['name' => '  Ionity ']));
check('create_provider trims the name and answers with the id', $err === null && $res->data()['providerName'] === 'Ionity' && $res->data()['id'] > 0);
[, $err] = call('create_provider', Request::make('POST', [], ['name' => '  ']));
check('create_provider with an empty name is a 400', $err?->status === 400 && $err->getMessage() === 'Navn må ikke være tomt');
[, $err] = call('update_vehicle', Request::make('POST', [], ['id' => 99, 'name' => 'x']));
check('update_vehicle on an unknown vehicle is a 404', $err?->status === 404);
[$res, $err] = call('update_vehicle', Request::make('POST', [], ['id' => 2, 'name' => 'e-Up 2']));
check('update_vehicle renames', $err === null && $res->data() === ['success' => true] && DatabaseManager::getChargesDb()->query("SELECT vehicleName FROM vehicles WHERE id = 2")->fetchColumn() === 'e-Up 2');
[$res] = call('create_ext_charge', Request::make('POST', [], ['vehicleId' => 1, 'providerId' => 1, 'chargeDateTime' => '2026-03-01T10:00', 'kwh' => 12.5, 'pris' => 45]));
check('create_ext_charge answers 201 with the new id', $res->status === 201 && $res->data()['id'] > 0);
[, $err] = call('create_ext_charge', Request::make('POST', [], ['vehicleId' => 1, 'providerId' => 1, 'chargeDateTime' => 'not a date', 'kwh' => 12.5, 'pris' => 45]));
check('create_ext_charge with a bad date is a 400 (ChargeInput fails through the same exception)', $err?->status === 400);
[, $err] = call('delete_provider', Request::make('POST', [], ['id' => 1]));
check('delete_provider that is in use is a 409 naming the count', $err?->status === 409 && str_contains($err->getMessage(), '1 ladning'));

echo "machine endpoints (Bearer)\n";
$bearer = ['Authorization' => 'Bearer vehicle-key'];
$day = date('Y-m-d', strtotime('-1 day'));
[, $err] = call('receive_device_energy', Request::make('POST', [], ['date' => $day, 'devices' => ['varme_skur' => 1.5]]));
check('without a key: 401 with WWW-Authenticate', $err?->status === 401 && isset($err->headers['WWW-Authenticate']));
[, $err] = call('receive_device_energy', Request::make('POST', [], ['date' => $day, 'devices' => ['varme_skur' => 1.5]], ['Authorization' => 'Bearer wrong']));
check('with a wrong key: 401', $err?->status === 401);
[, $err] = call('receive_device_energy', Request::make('GET', [], [], $bearer));
check('GET: 405', $err?->status === 405);
[$res, $err] = call('receive_device_energy', Request::make('POST', [], ['date' => $day, 'devices' => ['varme_skur' => 1.5]], $bearer));
check('a valid POST: 201 and the row is stored', $err === null && $res->status === 201 && $res->data()['rows'] === 1 && DeviceEnergy::daily(DatabaseManager::getPowerlogDb(), 'varme_skur', $day, $day) === [$day => 1.5]);
[, $err] = call('receive_device_energy', Request::make('POST', [], ['date' => 'x', 'devices' => ['varme_skur' => 1]], $bearer));
check('a bad payload: 400', $err?->status === 400);
Config::override(['vehicleApiKey' => '']);
[, $err] = call('receive_device_energy', Request::make('POST', [], ['date' => $day, 'devices' => ['a' => 1]], $bearer));
check('no key configured: 503 (fails closed, even for a request that carries a key)', $err?->status === 503);
Config::override(['vehicleApiKey' => 'vehicle-key']);
[$res, $err] = call('receive_vehicle_data', Request::make('POST', [], ['vehicleId' => 1, 'odometer' => 31000], $bearer));
check('receive_vehicle_data stores an odometer reading (201)', $err === null && $res->status === 201);
[, $err] = call('receive_vehicle_data', Request::make('POST', [], ['vehicleId' => 1, 'odometer' => -5], $bearer));
check('...and refuses a negative one (400)', $err?->status === 400);
$ev = ['event' => 'plugged', 'eventAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'status' => 'awaiting_authorization'];
[$res] = call('receive_charger_event', Request::make('POST', [], $ev, $bearer));
[$res2] = call('receive_charger_event', Request::make('POST', [], $ev, $bearer));
check('receive_charger_event: 201 the first time, 200 "duplicate" the second', $res->status === 201 && $res2->status === 200 && $res2->data()['message'] === 'Duplicate ignored');
[, $err] = call('health', Request::make('GET'));
check('health without HEALTH_KEY configured: 503', $err?->status === 503);
Config::override(['healthKey' => 'health-key']);
[$res, $err] = call('health', Request::make('GET', [], [], ['Authorization' => 'Bearer health-key']));
check('health with the key answers a verdict (503 when a source is stale, 200 when not) and lists the checks', $err === null && in_array($res->status, [null, 503], true) && !empty($res->data()['checks']));

echo "system log\n";
[, $err] = call('get_system_log', Request::make('GET'));
check('without a session token or the admin key: 403', $err?->status === 403);
[$res, $err] = call('get_system_log', Request::make('GET', ['lines' => '5'], [], ['X-CSRF-Token' => 'abc'], ['csrf_token' => 'abc']));
check('with the session\'s CSRF token: the log (a list, at most the lines asked for)', $err === null && is_array($res->data()['lines']) && count($res->data()['lines']) <= 5);
[$res, $err] = call('get_system_log', Request::make('GET', [], [], ['Authorization' => 'Bearer admin-key']));
check('with the admin key: allowed', $err === null && $res instanceof Response);

echo "cached reads\n";
[$first] = call('get_dashboard_summary', Request::make('GET'));
$files = glob("$dir/cache/sparkspend_dashboard_*.json");
check('the dashboard handler answers and stores the answer under its registered key', isset($first->data()['ev']) && count($files) === 1);
[$second] = call('get_dashboard_summary', Request::make('GET'));
check('the second call is served from the cache: the same body', $second->body === $first->body);
[$res] = call('get_base_load', Request::make('GET', ['months' => '6']));
check('get_base_load (cached, parameterised)', isset($res->data()['months']) && glob("$dir/cache/sparkspend_baseload_6_*.json") !== []);
[, $err] = call('get_hourly_consumption', Request::make('GET', ['start' => '2026-01-01', 'end' => '2026-06-01']));
check('hourly consumption refuses a range over 62 days (400)', $err?->status === 400);

echo "Router::run() sends what a handler produced\n";
function run_route(string $route, Request $req): array {
    http_response_code(200);   // a web request starts at 200; in this one process the previous test's status would stay
    ob_start(); Router::run("routes/$route.php", $route, $req); $out = ob_get_clean();
    return [$out, http_response_code()];
}
[$out, $code] = run_route('update_vehicle', Request::make('POST', [], ['id' => 99, 'name' => 'x']));
check('an HttpException becomes the error envelope with its status', json_decode($out, true) === ['success' => false, 'error' => 'Køretøj ikke fundet'] && $code === 404, "$code $out");
[$out, $code] = run_route('get_vehicles', Request::make('GET'));
check('a Response is sent: its body and its status', is_array($j = json_decode($out, true)) && count($j) === 2 && $code === 200, "$code $out");
Config::override(['dbPath' => "$dir/missing.db"]); DatabaseManager::reset();
[$out, $code] = run_route('get_vehicles', Request::make('GET'));
check('an unexpected failure is a 500 that does not leak the cause', $code === 500 && !str_contains($out, 'missing.db') && str_contains($out, 'intern fejl'), $out);

echo "the pattern is kept (static scan of routes/)\n";
$bad = [];
foreach (glob(__DIR__ . '/../routes/*.php') as $f) {
    $code = str_replace("\r\n", "\n", file_get_contents($f));
    $code = preg_replace('~^if \(!defined\(\'SPARKSPEND_BOOTED\'\)\).*$~m', '', $code);          // the one guard line
    $code = preg_replace('~/\*.*?\*/~s', '', $code);                                           // doc blocks
    $code = preg_replace('~^\s*//.*$~m', '', $code);                                          // comment lines
    $name = basename($f);
    if (!preg_match('~^return static function \(Request \$request\)~m', $code)) $bad[] = "$name: does not return a handler";
    foreach (['/\becho\b/' => 'echo', '/\bexit\b|\bdie\b/' => 'exit', '/\$_(GET|POST|SERVER|SESSION|REQUEST|COOKIE)\b/' => 'a superglobal',
              '#php://input#' => 'php://input', '/\bhttp_response_code\(/' => 'http_response_code()', '/(?<![>:\w])header\(/' => 'header()',
              '/\bfunction\s+[a-z_]\w*\s*\(/i' => 'a named function in the global scope', '/\bdefine\(/' => 'define()'] as $re => $what) {
        if (preg_match($re, $code)) $bad[] = "$name: uses $what";
    }
}
check('every route returns a handler and uses no echo, exit, superglobal, header(), http_response_code(), global function or constant', $bad === [], implode('; ', $bad));

DatabaseManager::reset(); gc_collect_cycles();
array_map('unlink', array_merge(glob("$dir/cache/*") ?: [], glob("$dir/*.db*") ?: []));
@rmdir("$dir/cache"); @rmdir($dir);
echo "\n$n checks, $fails failed\n";
echo ob_get_clean();
exit($fails === 0 ? 0 : 1);
