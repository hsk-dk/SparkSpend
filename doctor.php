<?php
/**
 * Preflight / health check for a deployment. Changes nothing.
 *
 *   php doctor.php            # checks .env, folders, migrations, cron jobs and data freshness
 *   php doctor.php --no-cron  # skip the crontab check (e.g. when cron runs as another user)
 *   php doctor.php --probe=http://10.0.1.3 --host=sparkspend.example.dk
 *                             # also fetch .env, .git, the databases, logs and source over HTTP and fail
 *                             # if the web server hands any of them out. Probe the server's OWN address
 *                             # (not the public one: that only shows the login redirect).
 *
 * Exit code: 0 = ok or warnings only, 1 = at least one FAIL. Run it after every deploy.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

putenv('SPARKSPEND_DOCTOR=1');
require __DIR__ . '/includes/configuration.php';
Config::override(['autoMigrate' => false]);   // looking must not migrate
require __DIR__ . '/includes/DatabaseManager.php';
require __DIR__ . '/includes/MigrationRunner.php';

$skipCron = in_array('--no-cron', $argv ?? [], true);
$opt = static function (string $name) use ($argv): ?string {
    foreach (array_slice($argv ?? [], 1) as $a) if (str_starts_with($a, "--{$name}=")) return substr($a, strlen($name) + 3);
    return null;
};
$results  = [];
$add = static function (array ...$rs) use (&$results): void { foreach ($rs as $r) $results[] = $r; };

// PHP
$add(version_compare(PHP_VERSION, '8.3.0', '>=')
    ? ['level' => 'ok', 'name' => 'PHP ' . PHP_VERSION, 'detail' => '']
    : ['level' => 'warn', 'name' => 'PHP ' . PHP_VERSION, 'detail' => 'testet med 8.3']);
foreach (['pdo_sqlite', 'curl', 'mbstring'] as $ext) {
    $add(extension_loaded($ext) ? ['level' => 'ok', 'name' => "php-$ext", 'detail' => ''] : ['level' => 'fail', 'name' => "php-$ext mangler", 'detail' => '']);
}

// .env
$envFile = getenv('SPARKSPEND_ENV_FILE') ?: __DIR__ . '/.env';
if (!is_file($envFile)) {
    $add(['level' => 'fail', 'name' => '.env findes ikke', 'detail' => $envFile]);
    $env = [];
} else {
    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
    $add(...Doctor::checkEnv($env));
}

// Folders
$cache = Config::cacheDir();
$add(Doctor::checkDir('CACHE_DIR', $cache, is_dir($cache), is_dir($cache) && is_writable($cache), sys_get_temp_dir()));
$logPath = (string) ini_get('error_log');
$logDir  = dirname($logPath);
$add(Doctor::checkDir('Fejllogmappen', $logDir, is_dir($logDir), is_dir($logDir) && is_writable($logDir)));
$cronDir = __DIR__ . '/cron';
$add(Doctor::checkDir('cron/ (cron.log)', $cronDir, is_dir($cronDir), is_writable($cronDir)));

// Databases + migrations
foreach ([
    ['Charges-DB',  Config::dbPath(),        'getChargesDb',  __DIR__ . '/migrations/charges'],
    ['Powerlog-DB', Config::powerlogDbPath(), 'getPowerlogDb', __DIR__ . '/migrations/powerlog'],
] as [$label, $path, $getter, $dir]) {
    try {
        $db = DatabaseManager::$getter();
        $add(['level' => 'ok', 'name' => "$label kan åbnes", 'detail' => $path]);
        $add(Doctor::checkMigrations($label, MigrationRunner::status($db, $dir)));
        $add(...DataIntegrity::check($db, $label));   // numbers stored as text/blob, rows that point at nothing
    } catch (\Throwable $e) {
        $add(['level' => 'fail', 'name' => "$label kan ikke åbnes", 'detail' => $e->getMessage()]);
    }
}

// Cron
if (!$skipCron) {
    $tab = '';
    if (function_exists('shell_exec')) {
        $tab = (string) @shell_exec('crontab -l 2>/dev/null') . "\n" . (is_readable('/etc/crontab') ? (string) @file_get_contents('/etc/crontab') : '');
    }
    if (trim($tab) === '') {
        $add(['level' => 'warn', 'name' => 'Crontab kunne ikke læses', 'detail' => 'kør som den bruger, der ejer cron, eller brug --no-cron']);
    } else {
        $add(...Doctor::checkCrontab($tab, 'is_file'));
    }
}

// Data freshness (the same verdict /health.php gives)
try {
    $add(...Doctor::checkFreshness(Freshness::collect(
        DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb(), $cache, time()
    )));
} catch (\Throwable $e) {
    $add(['level' => 'fail', 'name' => 'Datafriskhed kunne ikke vurderes', 'detail' => $e->getMessage()]);
}

// Exposure: which files does the web server hand out? (--probe=http://10.0.1.3 --host=sparkspend.example.dk)
if (($probe = $opt('probe')) !== null) {
    $add(...Exposure::probe($probe, $opt('host')));
}

// Output
$mark = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]'];
foreach ($results as $r) {
    echo $mark[$r['level']] . ' ' . $r['name'] . ($r['detail'] !== '' ? '  - ' . $r['detail'] : '') . "\n";
}
$worst = Doctor::worst($results);
$count = array_count_values(array_column($results, 'level'));
echo "\n" . ($count['ok'] ?? 0) . ' ok, ' . ($count['warn'] ?? 0) . ' advarsler, ' . ($count['fail'] ?? 0) . " fejl\n";
exit($worst === 'fail' ? 1 : 0);
