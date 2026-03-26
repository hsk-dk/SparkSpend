<?php
/**
 * Trigger Sync API
 *
 * Launches one or all cron sync scripts in the background (non-blocking).
 * The web server user must have execute permission on the PHP scripts.
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

// PHP_BINARY gives the full path to the current PHP executable
$php = escapeshellcmd(PHP_BINARY);
$started = 0;

foreach ($toRun as $script) {
    if (!file_exists($script)) {
        continue;
    }
    // Run in background: redirect stdout + stderr to /dev/null, trailing & detaches
    exec($php . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    $started++;
}

// Invalidate sync status cache so next getSyncStatus.php call returns fresh data
$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_syncstatus.json';
@unlink($cacheFile);

echo json_encode(['success' => true, 'started' => $started]);
?>
