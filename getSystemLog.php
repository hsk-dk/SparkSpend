<?php
/**
 * System Log API
 *
 * Returns the last N lines from cron/trigger.log.
 * Path is hardcoded — no user-supplied path accepted.
 *
 * GET parameters:
 *   lines  int  Number of tail lines to return (default 50, max 200)
 *
 * Response:
 * {
 *   "lines":     ["...", ...],   // ordered oldest → newest
 *   "total":     int,            // total lines in file
 *   "truncated": bool,           // true when file has more lines than requested
 *   "missing":   bool            // true when log file does not exist yet
 * }
 */

require 'includes/configuration.php';

header('Content-Type: application/json');

// Hardcoded log path — never derived from user input
$logPath = __DIR__ . '/cron/trigger.log';

$maxAllowed = 200;
$requested  = min($maxAllowed, max(1, (int) ($_GET['lines'] ?? 50)));

if (!file_exists($logPath)) {
    echo json_encode(['lines' => [], 'total' => 0, 'truncated' => false, 'missing' => true]);
    exit;
}

// Read entire file and tail it — trigger.log is small (cron output, KB range)
$all   = file($logPath, FILE_IGNORE_NEW_LINES);
$total = count($all);

if ($total === 0) {
    echo json_encode(['lines' => [], 'total' => 0, 'truncated' => false, 'missing' => false]);
    exit;
}

$truncated = $total > $requested;
$lines     = array_slice($all, -$requested);

echo json_encode([
    'lines'     => $lines,
    'total'     => $total,
    'truncated' => $truncated,
    'missing'   => false,
]);
