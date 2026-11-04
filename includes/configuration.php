<?php
/**
 * SparkSpend Application Configuration
 *
 * Loads configuration from environment variables (.env file)
 * All sensitive values should be stored in .env, not in source code
 */

// Load environment variables from .env file
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/autoload.php';   // classes in includes/ load on first use

// Load all config values into the Config class
Config::load();

// Timezone Configuration
$timezone = env('TIMEZONE', 'Europe/Copenhagen');
date_default_timezone_set($timezone);

// Error Reporting Configuration
error_reporting(E_ALL);
ini_set('display_errors', 0); // Never display errors to users
ini_set('log_errors', 1);

// Error Logging Path
$logPath = env('LOG_PATH', '/var/log/php_errors.log');
ini_set('error_log', $logPath);

// ============================================================================
// Security Headers — sent on every response (HTML + JSON API)
// ============================================================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Content-Security-Policy. script-src has NO 'unsafe-inline': the page has no inline <script>
// and no inline event-handler attributes (clicks use data-action, see includes/actions.js;
// the server config is a JSON data block read by includes/config.js). So an injected
// <script> or onerror= from stored data does not run, even if some output was not escaped.
// Library scripts come from cdn.jsdelivr.net with Subresource Integrity.
// style-src keeps 'unsafe-inline': the UI uses many inline style="" attributes (and the chart
// libraries set styles); inline styles cannot run code. Tightening that is a separate task.
header("Content-Security-Policy: default-src 'self'; script-src 'self' cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com cdnjs.cloudflare.com; font-src 'self' fonts.gstatic.com cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self' cdn.jsdelivr.net https://archive-api.open-meteo.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

// ============================================================================
// CSRF Protection
// ============================================================================

// Start PHP session if not already started (used solely for CSRF token)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        // Always secure, except for explicit local http dev (DEV_INSECURE_COOKIE=true)
        'secure'   => env('DEV_INSECURE_COOKIE', 'false') !== 'true',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Generate a CSRF token once per session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Verify CSRF token on state-mutating requests (POST/PUT/PATCH/DELETE).
 * Call at the top of every mutating endpoint.
 * On failure: emits HTTP 403 JSON and exits.
 */
function csrfVerify(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    $token   = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $session = $_SESSION['csrf_token']        ?? '';
    if (!$session || !hash_equals($session, $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Ugyldig CSRF-token']);
        exit;
    }
}

/**
 * Build a cache-busted URL for a local asset (CSS/JS).
 *
 * Appends ?v=<filemtime> so the browser always fetches a fresh copy after a
 * deploy without any manual version bumping. Falls back to a date stamp if the
 * file can't be stat'd (e.g. path typo) so the page still renders.
 *
 * @param string $relPath Path relative to the app root, e.g. 'includes/app.js'
 * @return string The path with a version query string
 */
function asset(string $relPath): string {
    $full = __DIR__ . '/../' . ltrim($relPath, '/');
    $ver  = @filemtime($full) ?: date('Ymd');
    return htmlspecialchars($relPath, ENT_QUOTES) . '?v=' . $ver;
}
?>