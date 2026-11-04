<?php
/**
 * Trigger Sync API
 *
 * Launches one or all cron sync scripts in the background (non-blocking).
 *
 * POST body (JSON): { "source": "monta" | "heatpump" | "hus" | "all" }
 */

$source = Http::str(Http::body(), 'source');

// Whitelist of allowed sources → absolute script paths
$scripts = [
    'monta'    => __DIR__ . '/../cron/update_monta_data.php',
    'heatpump' => __DIR__ . '/../cron/sync_heatpump_data.php',
    'hus'      => __DIR__ . '/../cron/sync_housepowerlog_data.php',
];

if ($source === 'all') {
    $toRun = array_values($scripts);
} elseif (isset($scripts[$source])) {
    $toRun = [$scripts[$source]];
} else {
    Http::fail('Ukendt kilde');
}

// Rate-limit: max 1 sync request per 30 seconds
$_cacheDir    = Config::cacheDir();
$_cooldownFile = $_cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_sync_cooldown.lock';
if (file_exists($_cooldownFile) && (time() - filemtime($_cooldownFile)) < 30) {
    Http::fail('Vent venligst 30 sekunder mellem synkroniseringer', 429);
}
touch($_cooldownFile);

// Resolve PHP CLI binary
$php     = _resolvePhpCli();
$logFile = __DIR__ . '/../cron/trigger.log';
$logPath = escapeshellarg($logFile);
$started = 0;

// Invalidate all file caches
$_cacheDir = Config::cacheDir();
foreach (glob($_cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_*.json') ?: [] as $_f) {
    @unlink($_f);
}

// Per-script lock: never start a script that is still running from an earlier
// trigger (parallel writers would collide on SQLite and the shared log).
$flockBin = is_executable('/usr/bin/flock') ? '/usr/bin/flock' : null;
$lockDir  = Config::cacheDir();
$isRunning = static function (string $lockFile): bool {
    $h = @fopen($lockFile, 'c');
    if ($h === false) return false;
    $free = flock($h, LOCK_EX | LOCK_NB);
    if ($free) flock($h, LOCK_UN);
    fclose($h);
    return !$free;
};

$anyRunning = false;
foreach ($toRun as $script) {
    if ($flockBin && $isRunning($lockDir . DIRECTORY_SEPARATOR . 'sparkspend_sync_' . basename($script) . '.lock')) {
        $anyRunning = true;
    }
}
// Truncate the log before a triggered sync — but not while one is still writing to it
if (!$anyRunning) {
    file_put_contents($logFile, '');
}

foreach ($toRun as $script) {
    if (!file_exists($script)) {
        continue;
    }

    $cmd = $php . ' ' . escapeshellarg($script) . ' >> ' . $logPath . ' 2>&1';
    if ($flockBin) {
        $lockFile = $lockDir . DIRECTORY_SEPARATOR . 'sparkspend_sync_' . basename($script) . '.lock';
        // -n: skip silently if the previous run still holds the lock
        $cmd = $flockBin . ' -n ' . escapeshellarg($lockFile) . ' ' . $cmd;
    }

    if (_runBackground($cmd)) {
        $started++;
    }
}

echo json_encode(['success' => true, 'started' => $started]);

/**
 * Resolve the PHP CLI binary path.
 */
function _resolvePhpCli(): string
{
    $fpmBinary = PHP_BINARY;

    $version = '';
    if (preg_match('/(\d+\.\d+)$/', $fpmBinary, $m)) {
        $version = $m[1];
    }

    $candidates = [];
    if ($version !== '') {
        $candidates[] = '/usr/bin/php' . $version;
    }
    $candidates[] = '/usr/bin/php';
    $candidates[] = '/usr/local/bin/php';

    foreach ($candidates as $path) {
        if (is_executable($path)) {
            return escapeshellcmd($path);
        }
    }

    return escapeshellcmd($fpmBinary);
}

/**
 * Run a shell command in the background.
 */
function _runBackground(string $cmd): bool
{
    if (function_exists('exec')) {
        exec($cmd . ' &');
        return true;
    }

    if (function_exists('proc_open')) {
        $spec  = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = [];
        $proc  = proc_open($cmd . ' &', $spec, $pipes);
        if ($proc !== false) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($proc);
            return true;
        }
    }

    if (function_exists('shell_exec')) {
        shell_exec($cmd . ' &');
        return true;
    }

    if (function_exists('popen')) {
        $handle = popen($cmd . ' &', 'r');
        if ($handle !== false) {
            pclose($handle);
            return true;
        }
    }

    return false;
}
