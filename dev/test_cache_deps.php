<?php
/**
 * Cache dependencies: the registry in CacheHelper::DEPENDENCIES against the code that uses it.
 *
 *   - every route that caches registers its key prefix (and no registered prefix is dead),
 *   - every writer (route, cron job) invalidates the tags of the data it changes,
 *   - a route that writes but is not on the writers list fails the test (so a NEW writer has to be
 *     thought about),
 *   - the invalidation itself: right files go, the others stay; unknown tags and keys are refused.
 *
 * Run:  php dev/test_cache_deps.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';
require __DIR__ . '/../includes/autoload.php';
Config::load();

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
$root = dirname(__DIR__);
$src  = fn(string $rel) => str_replace("\r\n", "\n", (string) file_get_contents("$root/$rel"));

echo "registry\n";
$allTags = array_keys(CacheHelper::TAGS);
$badTags = [];
foreach (CacheHelper::DEPENDENCIES as $prefix => $tags) foreach ($tags as $t) if (!in_array($t, $allTags, true)) $badTags[] = "$prefix:$t";
check('every dependency uses a declared tag', $badTags === [], implode(', ', $badTags));
check('every view depends on something (a view with no tags would never be invalidated)', !array_filter(CacheHelper::DEPENDENCIES, fn($t) => $t === []));
$unused = [];
foreach ($allTags as $t) if (!array_filter(CacheHelper::DEPENDENCIES, fn($d) => in_array($t, $d, true))) $unused[] = $t;
check('every tag is used by at least one view', $unused === [], implode(', ', $unused));

echo "routes that cache\n";
$used = [];
foreach (glob("$root/routes/*.php") as $file) {
    $code = str_replace("\r\n", "\n", file_get_contents($file));
    if (!str_contains($code, 'ApiCache::')) continue;
    preg_match_all('/\w*[Kk]ey\w*\s*=\s*\'([a-z_]+)\'/', $code, $m1);
    preg_match_all('/ApiCache::(?:fetch|store)\(\s*\'([a-z_]+)\'/', $code, $m2);
    $prefixes = array_unique(array_merge($m1[1], $m2[1]));
    if (!$prefixes) check(basename($file) . ': a cache key prefix can be found (update this test if the key is built differently)', false);
    foreach ($prefixes as $p) {
        $used[$p] = true;
        check(basename($file) . ": '$p' is registered", CacheHelper::dependenciesOf($p . 'x') !== null || CacheHelper::dependenciesOf($p) !== null);
    }
}
$dead = array_diff(array_keys(CacheHelper::DEPENDENCIES), array_keys($used));
check('no registered prefix is unused by every route (dead entries mislead)', $dead === [], implode(', ', $dead));

echo "writers invalidate what they change\n";
/** file => tags it must invalidate. Reads are not listed. */
$writers = [
    'routes/create_ext_charge.php'      => ['charges'],
    'routes/update_ext_charge.php'      => ['charges'],
    'routes/delete_ext_charge.php'      => ['charges'],
    'routes/update_internal_charge.php' => ['charges'],
    'routes/create_provider.php'        => ['providers'],
    'routes/update_provider.php'        => ['providers'],
    'routes/delete_provider.php'        => ['providers'],
    'routes/update_vehicle.php'         => ['vehicles'],
    'routes/receive_vehicle_data.php'   => ['ha'],
    'routes/receive_charger_event.php'  => ['ha'],
    'routes/receive_device_energy.php'  => ['meter'],
    'includes/MeterSync.php'            => ['meter'],
    'cron/update_monta_data.php'        => ['charges'],
    'cron/warm_prices.php'              => ['prices'],
    'includes/CronJob.php'              => ['sync'],
];
foreach ($writers as $file => $tags) {
    $code = $src($file);
    $got = [];
    if (preg_match_all('/CacheHelper::invalidate\(\[([^\]]*)\]\)/', $code, $m)) {
        foreach ($m[1] as $list) preg_match_all('/\'([a-z]+)\'/', $list, $t) && $got = array_merge($got, $t[1]);
    }
    $missing = array_diff($tags, $got);
    check("$file invalidates " . implode(', ', $tags), $missing === [], 'missing: ' . implode(',', $missing));
}
$listed = array_keys($writers);
foreach (glob("$root/routes/*.php") as $file) {
    $rel = 'routes/' . basename($file);
    if (str_starts_with(basename($file), 'get_') || in_array($rel, $listed, true)) continue;
    $code = $src($rel);
    $writes = (bool) preg_match('/\b(INSERT|UPDATE|DELETE)\b\s+(INTO|FROM|\w+\s+SET|\w+)|Repository::(create|insert|update|delete|upsert)\w*\(/', $code)
           || str_contains($code, 'DeviceEnergy::store');
    // trigger_sync writes nothing itself: it starts the cron jobs, and clears every cache file first.
    if ($rel === 'routes/trigger_sync.php') $writes = false;
    check("$rel: a route that writes must be on the writers list above (it does not look like it writes)", !$writes);
}
check('trigger_sync clears every cache file before it starts the jobs', str_contains($src('routes/trigger_sync.php'), "sparkspend_*.json"));

echo "what is invalidated (the five gaps the hand-kept lists had)\n";
$for = fn(array $tags) => CacheHelper::prefixesFor($tags);
check('a charge change refreshes house power', in_array('housepower_', $for(['charges']), true));
check('a charge change refreshes the base load', in_array('baseload_', $for(['charges']), true));
check('a charge change refreshes the charge optimiser', in_array('chargeopt_', $for(['charges']), true));
check('Home Assistant pushes (odometer) refresh efficiency', in_array('efficiency_', $for(['ha']), true));
check('Home Assistant pushes (charger events) refresh the hourly home-EV views', array_diff(['hourlycons_', 'housepower_', 'bill_', 'baseload_'], $for(['ha'])) === []);
check('a vehicle rename refreshes the comparisons', array_diff(['analytics_', 'vehicle_compare_'], $for(['vehicles'])) === []);
check('new prices refresh everything with a cost in it', array_diff(['dashboard_', 'annual_', 'bill_', 'housepower_', 'chargeopt_'], $for(['prices'])) === []);
check('a provider change does not drop the meter views', !array_intersect(['heatpump_', 'housepower_', 'hourlycons_'], $for(['providers'])));
check('a meter sync does not drop the pure charge views', !array_intersect(['ev_compare_', 'analytics_', 'efficiency_'], $for(['meter'])));
$threw = false; try { CacheHelper::prefixesFor(['chargess']); } catch (InvalidArgumentException $e) { $threw = true; }
check('an unknown tag throws (a typo must not invalidate nothing)', $threw);

echo "files\n";
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cachedeps_' . getmypid();
@mkdir($dir);
Config::override(['cacheDir' => $dir]);
$touch = function (string $name, int $age = 0) use ($dir) { $f = "$dir/sparkspend_$name"; file_put_contents($f, '{}'); touch($f, time() - $age); return $f; };
$files = [];
foreach (array_keys(CacheHelper::DEPENDENCIES) as $p) $files[$p] = $touch($p . '2026-01-01_x.json');
$other = $touch('weather_abc.json');
CacheHelper::invalidate(['charges']);
$gone = array_keys(array_filter($files, fn($f) => !file_exists($f)));
$expect = array_keys(array_filter(CacheHelper::DEPENDENCIES, fn($d) => in_array('charges', $d, true)));
check('invalidate([charges]) removes exactly the views that depend on charges', $gone === $expect, json_encode(array_diff($expect, $gone)) . json_encode(array_diff($gone, $expect)));
check('...and leaves the rest (heat pump, sync, weather)', file_exists($files['heatpump_']) && file_exists($files['syncstatus']) && file_exists($other));
CacheHelper::invalidate(['meter', 'sync']);
check('several tags at once', !file_exists($files['heatpump_']) && !file_exists($files['syncstatus']));
check('invalidate([]) removes nothing', ($touch('heatpump_k.json') && (CacheHelper::invalidate([]) || true) && count(glob("$dir/sparkspend_heatpump_*.json")) === 1));

echo "ApiCache fails safe\n";
$answer = ApiCache::store('mystery_2026', ['a' => 1]);
check('an unregistered key is answered but NOT stored', $answer->body === '{"a":1}' && !glob("$dir/sparkspend_mystery_*"));
file_put_contents("$dir/sparkspend_mystery_2026.json", '{"stale":true}');
check('...and an unregistered key is never served from a file', ApiCache::fetch('mystery_2026', 3600) === null);
ApiCache::store('dashboard_2026-11-07', ['b' => 2]);
check('a registered key is stored, and fetched back', is_file("$dir/sparkspend_dashboard_2026-11-07.json") && ApiCache::fetch('dashboard_2026-11-07', 3600) === '{"b":2}');
check('an incomplete answer is returned but not stored', ApiCache::store('annual_2026-11-07', ['c' => 3], false)->body === '{"c":3}' && !is_file("$dir/sparkspend_annual_2026-11-07.json"));
check('longest prefix wins: chargeopt_3_... is the optimiser, not something shorter', CacheHelper::dependenciesOf('chargeopt_3_2026-11-07-13_12_7.4') === CacheHelper::DEPENDENCIES['chargeopt_']);
check('a key that merely contains a prefix is not registered', CacheHelper::dependenciesOf('x_dashboard_1') === null);

echo "purgeExpired()\n";
array_map('unlink', glob("$dir/*"));
$day = 86400;
$old  = $touch('dashboard_2026-11-01.json', 3 * $day);
$new  = $touch('dashboard_2026-11-07.json', 1 * $day);
$oldRaw = $touch('eds_old.dat', 50 * $day);
$newRaw = $touch('eds_new.dat', 40 * $day);   // still inside TTL_PAST (30 d) + STALE_MAX (7 d): must survive
$midRaw = $touch('eds_mid.dat', 20 * $day);       // a finished year's prices, fetched 20 days ago: valid, must survive
$backoff = $touch('eds_backoff.dat', 30 * $day);
$foreign = "$dir/not_ours.json"; file_put_contents($foreign, '{}'); touch($foreign, time() - 100 * $day);
$removed = CacheHelper::purgeExpired();
check('old JSON views and expired raw price files are removed', !file_exists($old) && !file_exists($oldRaw) && $removed === 2, "removed=$removed");
check('recent files are kept; raw prices are kept for 40 days (30-day TTL + 7-day offline fallback)', file_exists($new) && file_exists($newRaw) && file_exists($midRaw));
check('the backoff marker and files that are not ours are never touched', file_exists($backoff) && file_exists($foreign));

array_map('unlink', glob("$dir/*")); rmdir($dir);
echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
