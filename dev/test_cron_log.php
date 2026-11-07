<?php
/**
 * CronLog: size-based rotation of cron/cron.log (it had grown to 56 MB in production).
 *
 * Run:  php dev/test_cron_log.php
 */
require __DIR__ . '/../includes/CronLog.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}

$dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cronlog_test_' . getmypid();
@mkdir($dir);
$log  = $dir . DIRECTORY_SEPARATOR . 'cron.log';
$read = fn(string $f) => is_file($f) ? file_get_contents($f) : null;

echo "appending\n";
CronLog::append($log, 'first', 100, 2);
CronLog::append($log, "second\n", 100, 2);
check('lines are appended, a newline is added when missing', $read($log) === "first\nsecond\n");
check('no rotation below the limit', !is_file($log . '.1'));

echo "rotation\n";
CronLog::append($log, str_repeat('x', 120), 100, 2);          // file is now over 100 bytes
CronLog::append($log, 'after-1', 100, 2);                       // this write rotates first
check('the big file became cron.log.1', is_file($log . '.1') && str_contains($read($log . '.1'), 'first'));
check('the new line starts a fresh cron.log', $read($log) === "after-1\n");

CronLog::append($log, str_repeat('y', 120), 100, 2);
CronLog::append($log, 'after-2', 100, 2);
CronLog::append($log, str_repeat('z', 120), 100, 2);
CronLog::append($log, 'after-3', 100, 2);
check('only LOG_KEEP rotated files are kept (.1 and .2)', is_file($log . '.1') && is_file($log . '.2') && !is_file($log . '.3'));
check('the oldest data was dropped', !str_contains($read($log . '.1') . $read($log . '.2'), 'first'));
check('.1 holds the newest rotated data, .2 the older', str_contains($read($log . '.1'), 'z') && str_contains($read($log . '.2'), 'y'));
check('files() lists oldest first, current file last', CronLog::files($log, 2) === [$log . '.2', $log . '.1', $log]);

echo "safety\n";
check('rotating a missing file does nothing', CronLog::rotateIfNeeded($dir . '/nope.log', 10, 2) === false);
check('a file under the limit is not rotated', CronLog::rotateIfNeeded($log, 1000000, 2) === false);
$big = $dir . '/big.log'; file_put_contents($big, str_repeat('a', 5000));
check('an over-limit file is rotated by rotateIfNeeded', CronLog::rotateIfNeeded($big, 1000, 1) === true && !is_file($big) && filesize($big . '.1') === 5000);
check('writing to an unwritable path does not throw', (function () { try { CronLog::append('/nonexistent-dir/x/cron.log', 'x', 10, 1); return true; } catch (Throwable $e) { return false; } })());

// cleanup
foreach (glob($dir . '/*') as $f) @unlink($f);
@rmdir($dir);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
