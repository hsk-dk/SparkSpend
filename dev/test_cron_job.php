<?php
/**
 * CronJob (lock, log, exit codes) and SyncLog (watermark / error). Run:  php dev/test_cron_job.php
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
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cronjob_test_' . getmypid();
@mkdir($dir);
Config::override(['cacheDir' => $dir]);
$logFile = $dir . DIRECTORY_SEPARATOR . 'cron.log';
$log = fn() => is_file($logFile) ? file_get_contents($logFile) : '';

echo "lock\n";
$a = CronJob::acquire('t1');
check('the first acquire gets the lock', $a !== null);
check('a second acquire while it is held is refused', CronJob::acquire('t1') === null);
check('isRunning sees a held lock', CronJob::isRunning('t1'));
check('another job has its own lock', ($b = CronJob::acquire('t2')) !== null);
CronJob::release($b);
CronJob::release($a);
check('after release the lock can be taken again', ($c = CronJob::acquire('t1')) !== null);
CronJob::release($c);
check('isRunning is false when nothing holds it', !CronJob::isRunning('t1'));
check('the job name is sanitised in the file name', !str_contains(basename(CronJob::lockFile('../x y')), '..') && str_ends_with(CronJob::lockFile('a'), 'sparkspend_job_a.lock'));

echo "run()\n";
$called = 0;
check('a job that returns nothing is exit 0', CronJob::run('r1', 'Test', function ($l) use (&$called) { $called++; }, null, $logFile) === 0 && $called === 1);
check('a quiet job writes nothing to the log', $log() === '');
check('the exit code the job returns is passed on (2 = soft failure)', CronJob::run('r1', 'Test', fn($l) => 2, null, $logFile) === 2);
CronJob::run('r1', 'Test', function ($l) { $l('Synced 3 records'); $l('Careful', 'WARN'); }, null, $logFile);
check('log lines carry time, level and the label', (bool) preg_match('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] \[INFO\] Test: Synced 3 records$/m', $log()) && str_contains($log(), '[WARN] Test: Careful'));

$seen = null; $logBefore = strlen($log());
$code = CronJob::run('r2', 'Test', function ($l) { throw new RuntimeException('boom'); }, function ($e) use (&$seen) { $seen = $e->getMessage(); }, $logFile);
check('an exception gives exit 1, is logged as ERROR and handed to onError', $code === 1 && $seen === 'boom' && str_contains(substr($log(), $logBefore), '[ERROR] Test: Failed: boom'));
$seen = null;
$code = CronJob::run('r2', 'Test', function ($l) { return intdiv(1, 0); }, function ($e) use (&$seen) { $seen = get_class($e); }, $logFile);
check('an Error (not only an Exception) is caught too', $code === 1 && $seen === 'DivisionByZeroError');
$code = CronJob::run('r2', 'Test', fn($l) => throw new RuntimeException('x'), function ($e) { throw new RuntimeException('onError itself fails'); }, $logFile);
check('a failing onError does not turn into a crash', $code === 1 && str_contains($log(), 'Could not record the error'));
check('the lock is released after a failure', !CronJob::isRunning('r2'));

$held = CronJob::acquire('r3'); $ran = false;
$code = CronJob::run('r3', 'Test', function ($l) use (&$ran) { $ran = true; }, null, $logFile);
check('a run while another holds the lock does not run the job, and is exit 0', $code === 0 && !$ran && str_contains($log(), 'Already running'));
CronJob::release($held);
$inside = null;
CronJob::run('r4', 'Test', function ($l) use (&$inside) { $inside = CronJob::isRunning('r4'); }, null, $logFile);
check('the lock is held while the job runs', $inside === true);

echo "SyncLog\n";
$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE sync_log (source TEXT PRIMARY KEY, last_sync_timestamp TEXT, last_sync_count INTEGER, updated_at TEXT, error_message TEXT)");
$row = fn(string $s) => $db->query("SELECT * FROM sync_log WHERE source = '$s'")->fetch(PDO::FETCH_ASSOC);
check('no row: no watermark', SyncLog::watermark($db, 'x') === null);
SyncLog::success($db, 'x', 5, '2026-01-01 10:00:00', '2026-01-01 10:05:00');
$r = $row('x');
check('success stores watermark, count, run time and no error', $r['last_sync_timestamp'] === '2026-01-01 10:00:00' && (int) $r['last_sync_count'] === 5 && $r['updated_at'] === '2026-01-01 10:05:00' && $r['error_message'] === null);
check('watermark() reads it back', SyncLog::watermark($db, 'x') === '2026-01-01 10:00:00');
SyncLog::success($db, 'x', 0, null, '2026-01-01 10:10:00');
$r = $row('x');
check('a run with nothing new keeps the watermark but advances the run time', $r['last_sync_timestamp'] === '2026-01-01 10:00:00' && (int) $r['last_sync_count'] === 0 && $r['updated_at'] === '2026-01-01 10:10:00');
SyncLog::error($db, 'x', 'MySQL gone away', '2026-01-01 10:15:00');
$r = $row('x');
check('an error KEEPS the watermark (it used to be thrown away)', $r['last_sync_timestamp'] === '2026-01-01 10:00:00' && $r['error_message'] === 'MySQL gone away' && $r['updated_at'] === '2026-01-01 10:15:00');
SyncLog::success($db, 'x', 2, '2026-01-01 11:00:00', '2026-01-01 11:05:00');
check('the next success clears the error and moves the watermark', $row('x')['error_message'] === null && $row('x')['last_sync_timestamp'] === '2026-01-01 11:00:00');
SyncLog::error($db, 'fresh', 'first run failed');
check('an error on a source that never ran creates the row with no watermark', $row('fresh')['error_message'] === 'first run failed' && SyncLog::watermark($db, 'fresh') === null);
SyncLog::error($db, 'x', str_repeat('é', 800));
check('a long error message is cut to 500', strlen($row('x')['error_message']) <= 500 && strlen($row('x')['error_message']) >= 499);
check('a database without the table gives no watermark instead of an error', SyncLog::watermark(new PDO('sqlite::memory:'), 'x') === null);

array_map('unlink', glob($dir . '/*')); rmdir($dir);
echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
