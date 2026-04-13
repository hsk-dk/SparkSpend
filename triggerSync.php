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

// PHP_BINARY gives the full path to the current PHP executable.
// Redirect output to trigger.log so PHP errors from background processes are visible.
$php     = escapeshellcmd(PHP_BINARY);
$logPath = escapeshellarg(__DIR__ . '/cron/trigger.log');
$started = 0;

foreach ($toRun as $script) {
    if (!file_exists($script)) {
        continue;
    }

    $cmd = $php . ' ' . escapeshellarg($script) . ' >> ' . $logPath . ' 2>&1';

    if (_runBackground($cmd)) {
        $started++;
    }
}

// Invalidate sync status cache so next getSyncStatus.php call returns fresh data
$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_syncstatus.json';
@unlink($cacheFile);

echo json_encode(['success' => true, 'started' => $started]);

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
