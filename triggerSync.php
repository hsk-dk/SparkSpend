<?php
/**
 * Trigger Sync API
 *
 * Launches one or all cron sync scripts in the background (non-blocking).
 * Tries exec(), shell_exec(), and proc_open() in order — PHP-FPM often has
 * exec() in disable_functions while proc_open() remains available.
 *
 * POST body (JSON): { "source": "monta" | "heatpump" | "hus" | "all" }
 *
 * Response: { "success": true, "started": int }
 *         | { "error": string }
 */

require 'includes/configuration.php';

header('Content-Type: application/json');

// Rate-limit: max 1 sync request per 30 seconds
$_cacheDir    = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
$_cooldownFile = $_cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_sync_cooldown.lock';
if (file_exists($_cooldownFile) && (time() - filemtime($_cooldownFile)) < 30) {
    http_response_code(429);
    echo json_encode(['error' => 'Vent venligst 30 sekunder mellem synkroniseringer']);
    exit;
}
touch($_cooldownFile);

$data   = json_decode(file_get_contents('php://input'), true);
$source = isset($data['source']) ? (string) $data['source'] : '';

// Whitelist of allowed sources → absolute script paths
$scripts = [
    'monta'    => __DIR__ . '/cron/update_monta_data.php',
    'heatpump' => __DIR__ . '/cron/sync_heatpump_data.php',
    'hus'      => __DIR__ . '/cron/sync_housepowerlog_data.php',
];

if ($source === 'all') {
    $toRun = array_values($scripts);
} elseif (isset($scripts[$source])) {
    $toRun = [$scripts[$source]];
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ukendt kilde']);
    exit;
}

// PHP_BINARY under PHP-FPM resolves to the FPM daemon binary (e.g. php-fpm8.4),
// not the CLI interpreter. Derive the CLI binary by probing common paths in order:
//   1. php-cli sibling of PHP_BINARY (Debian/Ubuntu: /usr/bin/php8.4-cli is rare;
//      more common: /usr/bin/php8.X or /usr/bin/php)
//   2. Same directory as PHP_BINARY but with '-cli' suffix stripped / replaced
//   3. Fall back to PHP_BINARY itself (works on non-FPM setups)
$php     = _resolvePhpCli();
$logFile = __DIR__ . '/cron/trigger.log';
$logPath = escapeshellarg($logFile);
$started = 0;

// Invalidate all file caches so the next page request fetches fresh data
// instead of waiting out the TTL (up to 1 hour for annual summary).
$_cacheDir = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
foreach (glob($_cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_*.json') ?: [] as $_f) {
    @unlink($_f);
}

// Truncate the log before each triggered sync so the viewer always shows only
// the latest run. Append-only growth otherwise makes the log unreadable.
file_put_contents($logFile, '');

foreach ($toRun as $script) {
    if (!file_exists($script)) {
        continue;
    }

    $cmd = $php . ' ' . escapeshellarg($script) . ' >> ' . $logPath . ' 2>&1';

    if (_runBackground($cmd)) {
        $started++;
    }
}

echo json_encode(['success' => true, 'started' => $started]);

/**
 * Resolve the PHP CLI binary path.
 *
 * PHP_BINARY under PHP-FPM is the FPM daemon (e.g. /usr/sbin/php-fpm8.4),
 * not a CLI interpreter. We probe for the CLI sibling instead.
 */
function _resolvePhpCli(): string
{
    $fpmBinary = PHP_BINARY; // e.g. /usr/sbin/php-fpm8.4 or /usr/bin/php8.4

    // Extract the version suffix if present (e.g. "8.4" from "php-fpm8.4" or "php8.4")
    $version = '';
    if (preg_match('/(\d+\.\d+)$/', $fpmBinary, $m)) {
        $version = $m[1];
    }

    // Candidate CLI paths in preference order
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

    // Last resort — use PHP_BINARY as-is (works on plain CGI/CLI setups)
    return escapeshellcmd($fpmBinary);
}

/**
 * Run a shell command in the background.
 *
 * PHP-FPM commonly has exec()/shell_exec() in disable_functions.
 * Falls back through proc_open() and popen() so at least one works.
 *
 * Returns true if a process was launched (not a guarantee it succeeded).
 */
function _runBackground(string $cmd): bool
{
    // exec() — fastest, most reliable when available
    if (function_exists('exec')) {
        exec($cmd . ' &');
        return true;
    }

    // proc_open() — available on most PHP-FPM installs even when exec is disabled
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

    // shell_exec() — returns output as string, no background detach possible
    if (function_exists('shell_exec')) {
        shell_exec($cmd . ' &');
        return true;
    }

    // popen() — limited, but better than nothing
    if (function_exists('popen')) {
        $handle = popen($cmd . ' &', 'r');
        if ($handle !== false) {
            pclose($handle);
            return true;
        }
    }

    return false;
}
?>
