<?php
/**
 * System Log API
 *
 * Returns the last N lines from cron logs (merged from cron.log and trigger.log).
 *
 * GET parameters:
 *   lines  int  Number of tail lines to return (default 80, max 200)
 */

// Access guard — require a valid session CSRF token (proof the request comes
// from a page we served). This keeps the log at the same protection level as
// the rest of the API (assumed behind the auth proxy) WITHOUT exposing a
// long-lived admin secret to the browser.
//
// An optional ADMIN_KEY (Bearer header) is still accepted for server-to-server
// / CLI access (e.g. monitoring), so the endpoint stays usable without a session.
$sessionToken  = $_SESSION['csrf_token'] ?? '';
$submittedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfOk        = $sessionToken !== '' && hash_equals($sessionToken, $submittedCsrf);

$adminKey    = Config::adminKey();
$authHeader  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$bearer      = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';
$adminKeyOk  = $adminKey !== '' && hash_equals($adminKey, $bearer);

if (!$csrfOk && !$adminKeyOk) {
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
