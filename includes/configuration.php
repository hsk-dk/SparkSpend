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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com cdnjs.cloudflare.com; font-src fonts.gstatic.com cdnjs.cloudflare.com; img-src 'self' data: auth.useful.dk; connect-src 'self' cdn.jsdelivr.net https://archive-api.open-meteo.com");

// ============================================================================
// CSRF Protection
// ============================================================================

// Start PHP session if not already started (used solely for CSRF token)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
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
 * Emit a standardized JSON error response and terminate.
 *
 * All error responses follow the envelope: {"success": false, "error": "..."}
 *
 * @param string $message Human-readable error message
 * @param int    $httpCode HTTP status code (default 400)
 */
function jsonError(string $message, int $httpCode = 400): never {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}
?>