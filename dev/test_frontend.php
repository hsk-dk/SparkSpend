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
