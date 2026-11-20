<?php
/**
 * Where the file cache lives. The old default was the system temp directory, which some hosts empty (and the price
 * files, which EDS rate-limits hard, go with it). Now: CACHE_DIR if set, else data/cache/ next to the databases, created
 * on first use; the temp directory only if that cannot be made.
 * Run:  php dev/test_config_cachedir.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
putenv('CACHE_DIR');   // unset: the test is about the default
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
$norm = fn(string $p) => str_replace('\\', '/', $p);
$root = $norm(dirname(__DIR__));

echo "the default\n";
Config::reset(); Config::load();
check('without CACHE_DIR the cache is data/cache/ in the project, not the temp directory',
    $norm(Config::cacheDir()) === $root . '/data/cache', Config::cacheDir());
check('...it exists and is writable (created on first use)', is_dir(Config::cacheDir()) && is_writable(Config::cacheDir()));

echo "\nCACHE_DIR wins\n";
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_cachedir_test_' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv('CACHE_DIR=' . $tmp . DIRECTORY_SEPARATOR);
Config::reset(); Config::load();
check('CACHE_DIR is used (trailing slash trimmed)', $norm(Config::cacheDir()) === $norm($tmp), Config::cacheDir());
putenv('CACHE_DIR');
Config::reset(); Config::load();
rmdir($tmp);

echo "\ndefaultCacheDir()\n";
$root1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_root_' . bin2hex(random_bytes(4));
mkdir($root1);
$d = Config::defaultCacheDir($root1);
check('it makes data/cache/ below a root that has no data folder yet', $norm($d) === $norm($root1) . '/data/cache' && is_dir($d), $d);
check('...and reuses it the second time', Config::defaultCacheDir($root1) === $d);
// a root where data/ cannot be made: a FILE named data
$root2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_root_' . bin2hex(random_bytes(4));
mkdir($root2); file_put_contents($root2 . '/data', 'not a folder');
check('if data/cache cannot be made it falls back to the temp directory', Config::defaultCacheDir($root2) === sys_get_temp_dir(), Config::defaultCacheDir($root2));
@rmdir($d); @rmdir(dirname($d)); @rmdir($root1);
@unlink($root2 . '/data'); @rmdir($root2);

echo "\nthe web server hides it\n";
$nginx = file_get_contents(__DIR__ . '/../docs/nginx-sparkspend.conf');
check('docs/nginx-sparkspend.conf answers 404 for everything under data/ (so data/cache/ is not served)',
    str_contains($nginx, 'location ~ ^/(?:data|data-dev|') && str_contains($nginx, ')(?:/|$) { return 404; }'));
check('data/ is not in git (so the cache is not committed)', str_contains(file_get_contents(__DIR__ . '/../.gitignore'), "\ndata/\n"));

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
