<?php
/**
 * System Log API
 *
 * Returns the last N lines from cron logs (merged from cron.log and trigger.log).
 *
 * GET parameters:
 *   lines  int  Number of tail lines to return (default 80, max 200)
 */

// Admin key guard — fail closed if key is not configured or does not match.
$adminKey     = Config::adminKey();
$authHeader   = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$submittedKey = str_starts_with($authHeader, 'Bearer ')
    ? substr($authHeader, 7)
    : ($_GET['key'] ?? ''); // fallback to query param for backwards compat
if ($adminKey === '' || !hash_equals($adminKey, $submittedKey)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Adgang nægtet']);
    exit;
}

// Hardcoded log paths — never derived from user input
$cronLog    = __DIR__ . '/../cron/cron.log';
$triggerLog = __DIR__ . '/../cron/trigger.log';

$maxAllowed = 200;
$requested  = min($maxAllowed, max(1, (int) ($_GET['lines'] ?? 80)));

// Merge both log files
$all = [];

if (file_exists($cronLog)) {
    $lines = file($cronLog, FILE_IGNORE_NEW_LINES);
    if ($lines !== false) $all = array_merge($all, $lines);
}

if (file_exists($triggerLog)) {
    $lines = file($triggerLog, FILE_IGNORE_NEW_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^\[\d{4}-\d{2}-\d{2}/', $line)) {
                $line = '[runtime] ' . $line;
            }
            $all[] = $line;
        }
    }
}

if (empty($all)) {
    $missing = !file_exists($cronLog) && !file_exists($triggerLog);
    echo json_encode(['lines' => [], 'total' => 0, 'truncated' => false, 'missing' => $missing]);
    exit;
}

$total     = count($all);
$truncated = $total > $requested;
$lines     = array_slice($all, -$requested);

echo json_encode([
    'lines'     => $lines,
    'total'     => $total,
    'truncated' => $truncated,
    'missing'   => false,
]);
