<?php
/**
 * Keeps the front end on its one request layer (includes/api.js) and its one escape function.
 * Static scan of includes/**.js and index.php; the behaviour of Api itself is in dev/test_api.js.
 * Run:  php dev/test_frontend.php
 */
$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
$root  = dirname(__DIR__);
$files = array_merge(glob("$root/includes/*.js") ?: [], glob("$root/includes/elbil/*.js") ?: []);
$rel   = fn(string $f) => str_replace('\\', '/', substr($f, strlen($root) + 1));
$src   = [];
foreach ($files as $f) $src[$rel($f)] = file_get_contents($f);
$routes = require "$root/includes/routes.php";

/** Strip // and /* *​/ comments, so a comment that mentions fetch( does not count. */
$code = fn(string $s) => preg_replace(['~/\*.*?\*/~s', '~(?<![:\'"])//[^\n]*~'], '', $s);

echo "One request layer\n";
$bad = [];
foreach ($src as $f => $s) {
    if (in_array($f, ['includes/api.js', 'includes/app.js'], true)) continue;   // api.js sends; app.js wraps window.fetch
    if (preg_match('/(?<![\w.])fetch\s*\(/', $code($s))) $bad[] = $f;
}
check('no module calls fetch() itself (use Api.get / Api.post)', $bad === [], implode(', ', $bad));
check('app.js only wraps fetch, it does not send requests', !preg_match('/await\s+fetch\s*\(|(?<![\w.])fetch\s*\(\s*[\'"`]/', $code($src['includes/app.js'])));
check('the old handleFetchResponse is gone', !preg_grep('/handleFetchResponse/', array_map($code, $src)));
$bad = [];
foreach ($src as $f => $s) if (preg_match("~['\"`]api\.php\?~", $code($s)) && $f !== 'includes/api.js') $bad[] = $f;
check('no module builds an api.php URL by hand', $bad === [], implode(', ', $bad));

echo "\nEvery action a module asks for is a real route, with the right verb\n";
$seen = [];
foreach ($src as $f => $s) {
    if (!preg_match_all('/\bApi\.(get|post)\(\s*([\'"])([a-z0-9-]+)\2/', $code($s), $m, PREG_SET_ORDER)) continue;
    foreach ($m as [, $verb, , $action]) $seen[$action][strtoupper($verb)][] = $f;
}
check('found the call sites', count($seen) >= 20, 'only ' . count($seen));
foreach ($seen as $action => $verbs) {
    foreach ($verbs as $verb => $in) {
        $route = $routes[$action] ?? null;
        check("$verb $action", $route !== null && $route['method'] === $verb,
            $route === null ? 'no such route (' . implode(', ', array_unique($in)) . ')' : 'route is ' . $route['method']);
    }
}
$dynamic = [];
foreach ($src as $f => $s) if (preg_match('/\bApi\.(get|post)\(\s*[^\s\'"]/', $code($s))) $dynamic[] = $f;
check('actions are literals (so this test can see them)', $dynamic === [], implode(', ', $dynamic));

echo "\nReads that need the CSRF token on a GET\n";
// A route that checks X-CSRF-Token on a GET (system-log) is not served by the fetch wrapper, which only adds it to
// POST/PUT/PATCH/DELETE: the caller must send it. Dropping it in the move to Api.get made the log panel say "Adgang nægtet".
$sysLog = file_get_contents("$root/routes/get_system_log.php");
check('system-log still checks X-CSRF-Token', str_contains($sysLog, "header('X-CSRF-Token')"));
check('so the sync modal sends it with Api.get', (bool) preg_match("~Api\.get\('system-log'.*?X-CSRF-Token~s", $src['includes/sync-modal.js']));

echo "\nChart.js plugin registration\n";
// The datalabels plugin must exist before the first chart is made (see app.js); registering it on first use of one tab broke
// charts created earlier with "reading '_listened'".
$reg = [];
foreach ($src as $f => $s) if (preg_match('/Chart\.register\s*\(\s*ChartDataLabels/', $code($s))) $reg[] = $f;
check('ChartDataLabels is registered once, in app.js', $reg === ['includes/app.js'], implode(', ', $reg));
$bad = [];
foreach ($src as $f => $s) if ($f !== 'includes/app.js' && preg_match('/new Chart\(/', $code($s)) && !str_contains($s, 'datalabels')) $bad[] = $f;
check('every file that makes a Chart.js chart configures datalabels itself', $bad === [], implode(', ', $bad));

echo "\nJordvarme: a view switch must not re-render the old rows\n";
// Cost and weather answers re-render from lastKwhData; after a switch (ytd -> monthly ...) it still holds the old view's rows,
// which have another shape: "reading 'month'" / "reading 'slice'". The click handler clears it before it fetches.
check('the mode-button handler clears lastKwhData before fetchAndRender()',
    (bool) preg_match('/currentMode\s*=\s*this\.dataset\.mode;.*?lastKwhData\s*=\s*null;.*?fetchAndRender\(\)/s', $src['includes/jordvarme.js']));

echo "\nOne escape function\n";
$bad = [];
foreach ($src as $f => $s) {
    if ($f === 'includes/app.js') continue;
    if (preg_match('~replace\(\s*/&/g~', $code($s))) $bad[] = $f;
}
check('no module has its own HTML escaping', $bad === [], implode(', ', $bad));
check('app.js escapeHtml accepts null/undefined/numbers', str_contains($src['includes/app.js'], 'String(text ?? \'\')'));
check('Elbil.esc is appUtils.escapeHtml', str_contains($src['includes/elbil/core.js'], 'const esc = appUtils.escapeHtml;'));

echo "\nLoading\n";
$index = file_get_contents("$root/index.php");
$pos = fn(string $f) => strpos($index, "includes/$f') ?>");
check('index.php loads api.js', $pos('api.js') !== false);
check('api.js loads after app.js and before the first module', $pos('app.js') < $pos('api.js') && $pos('api.js') < $pos('nav.js'));
check('every includes/*.js is loaded by index.php', (function () use ($src, $index) {
    foreach (array_keys($src) as $f) if (!str_contains($index, "asset('$f')")) { echo "       not loaded: $f\n"; return false; }
    return true;
})());

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
