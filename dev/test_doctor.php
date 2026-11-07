<?php
/**
 * Doctor: the preflight checks (pure). Run:  php dev/test_doctor.php
 */
require __DIR__ . '/../includes/Doctor.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
$lvl = fn(array $rs, string $needle) => array_values(array_map(fn($r) => $r['level'], array_filter($rs, fn($r) => str_contains($r['name'], $needle))));

echo "checkEnv()\n";
$good = ['ELSPOT_AREA' => 'DK2', 'ELSPOT_GLN' => '579', 'VEHICLE_API_KEY' => str_repeat('a', 32), 'HEALTH_KEY' => str_repeat('b', 32),
         'MONTA_CLIENT_ID' => 'x', 'MONTA_CLIENT_SECRET' => 'y', 'MYSQL_HEATPUMP_HOST' => 'h', 'MYSQL_HEATPUMP_USER' => 'u', 'MYSQL_HEATPUMP_DATABASE' => 'd',
         'EDS_WEB_FETCH' => 'false', 'AUTO_MIGRATE' => 'false'];
check('a complete production env has no warnings or failures', Doctor::worst(Doctor::checkEnv($good)) === 'ok', json_encode(Doctor::checkEnv($good)));
check('a missing price area is a failure', Doctor::worst(Doctor::checkEnv(array_diff_key($good, ['ELSPOT_AREA' => 1]))) === 'fail');
check('a missing HEALTH_KEY is a warning, not a failure', Doctor::worst(Doctor::checkEnv(array_diff_key($good, ['HEALTH_KEY' => 1]))) === 'warn');
check('the same key for health and vehicles is warned about', in_array('warn', $lvl(Doctor::checkEnv(['HEALTH_KEY' => $good['VEHICLE_API_KEY']] + $good), 'HEALTH_KEY'), true));
check('a short API key is warned about', in_array('warn', $lvl(Doctor::checkEnv(['VEHICLE_API_KEY' => 'abc'] + $good), 'kort'), true));
check('EDS_WEB_FETCH not false is warned about (the rate-limit trap)', in_array('warn', $lvl(Doctor::checkEnv(['EDS_WEB_FETCH' => 'true'] + $good), 'EDS_WEB_FETCH'), true));
check('DEBUG=true is warned about', in_array('warn', $lvl(Doctor::checkEnv(['DEBUG' => 'true'] + $good), 'DEBUG'), true));
check('an empty env fails', Doctor::worst(Doctor::checkEnv([])) === 'fail');

echo "checkCrontab()\n";
$all = <<<CRON
# comment /var/www/monta/cron/warm_prices.php
*/15 * * * * /usr/bin/php /var/www/monta/cron/update_monta_data.php >> /var/log/m.log 2>&1
*/5  * * * * /usr/bin/php /var/www/monta/cron/sync_heatpump_data.php
*/5  * * * * /usr/bin/php /var/www/monta/cron/sync_housepowerlog_data.php
*/30 * * * * /usr/bin/php /var/www/monta/cron/warm_prices.php >> /var/www/monta/cron/prices.log 2>&1
CRON;
check('a correct crontab is ok', Doctor::worst(Doctor::checkCrontab($all, fn($p) => true)) === 'ok');
check('a commented-out line does not count as scheduled', Doctor::worst(Doctor::checkCrontab("# */30 * * * * /usr/bin/php /var/www/monta/cron/warm_prices.php\n" . str_replace('warm_prices', 'zz', $all), fn($p) => true)) === 'fail');
check('a job that was never scheduled fails', in_array('fail', $lvl(Doctor::checkCrontab(str_replace('sync_heatpump_data', 'x', $all), fn($p) => true), 'sync_heatpump_data'), true));
check('a placeholder path (/sti/til) fails', in_array('fail', $lvl(Doctor::checkCrontab(str_replace('/var/www/monta', '/sti/til/sparkspend', $all), fn($p) => true), 'warm_prices'), true));
check('a path to a file that does not exist fails (the /var/monta mistake)', in_array('fail', $lvl(Doctor::checkCrontab(str_replace('/var/www/monta/cron/warm_prices', '/var/monta/cron/warm_prices', $all), fn($p) => str_starts_with($p, '/var/www/')), 'warm_prices'), true));
check('an empty crontab fails every job', count(array_filter(Doctor::checkCrontab('', fn($p) => true), fn($r) => $r['level'] === 'fail')) === count(Doctor::JOBS));

echo "checkDir() / checkMigrations() / checkFreshness()\n";
check('a missing folder fails', Doctor::checkDir('X', '/a', false, false)['level'] === 'fail');
check('a read-only folder fails', Doctor::checkDir('X', '/a', true, false)['level'] === 'fail');
check('the system temp dir as cache is a warning', Doctor::checkDir('CACHE_DIR', '/tmp/', true, true, '/tmp')['level'] === 'warn');
check('a proper folder is ok', Doctor::checkDir('CACHE_DIR', '/var/cache/x', true, true, '/tmp')['level'] === 'ok');
check('all migrations applied is ok', Doctor::checkMigrations('DB', [['filename' => '001', 'status' => 'applied']])['level'] === 'ok');
$m = Doctor::checkMigrations('DB', [['filename' => '001', 'status' => 'applied'], ['filename' => '005_x.php', 'status' => 'pending']]);
check('a pending migration fails and names the file', $m['level'] === 'fail' && str_contains($m['detail'], '005_x.php'));
check('fresh data is ok', Doctor::worst(Doctor::checkFreshness(['status' => 'ok', 'checks' => [['status' => 'ok']]])) === 'ok');
$f = Doctor::checkFreshness(['status' => 'degraded', 'checks' => [
    ['label' => 'Elpriser', 'status' => 'stale', 'age_sec' => 7200, 'max_age_sec' => 3600, 'optional' => false],
    ['label' => 'Enhed', 'status' => 'stale', 'age_sec' => 7200, 'max_age_sec' => 3600, 'optional' => true]]]);
check('stale required data fails, stale optional data only warns', $f[0]['level'] === 'fail' && $f[1]['level'] === 'warn');
check('worst() ranks fail above warn above ok', Doctor::worst([['level' => 'ok'], ['level' => 'warn']]) === 'warn' && Doctor::worst([['level' => 'warn'], ['level' => 'fail']]) === 'fail');

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
