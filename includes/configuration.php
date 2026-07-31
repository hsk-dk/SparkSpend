<?php
/**
 * SparkSpend Application Configuration
 *
 * Loads configuration from environment variables (.env file)
 * All sensitive values should be stored in .env, not in source code
 */

// Load environment variables from .env file
require_once 'env.php';

// Monta API Credentials - loaded from .env
$clientId = env('MONTA_CLIENT_ID');
$clientSecret = env('MONTA_CLIENT_SECRET');

// Validate that credentials are configured
if (empty($clientId) || empty($clientSecret)) {
    die('Error: MONTA_CLIENT_ID and MONTA_CLIENT_SECRET must be configured in .env file');
}

// Monta API Endpoints (static, no changes needed)
$authEndpoint = 'https://public-api.monta.com/api/v1/auth/token';
$dataEndpoint = 'https://public-api.monta.com/api/v1/charges';

// Database Paths - loaded from .env
$GLOBALS['dbPath'] = env('CHARGING_DB_PATH', 'data/charging_data.db');
$GLOBALS['powerlogDbPath'] = env('POWERLOG_DB_PATH', 'data/powerlog_data.db');

// MySQL Heat Pump Configuration - loaded from .env
$GLOBALS['mysqlHeatpumpHost'] = env('MYSQL_HEATPUMP_HOST');
$GLOBALS['mysqlHeatpumpPort'] = env('MYSQL_HEATPUMP_PORT', '3306');
$GLOBALS['mysqlHeatpumpUser'] = env('MYSQL_HEATPUMP_USER');
$GLOBALS['mysqlHeatpumpPassword'] = env('MYSQL_HEATPUMP_PASSWORD', '');
$GLOBALS['mysqlHeatpumpDatabase'] = env('MYSQL_HEATPUMP_DATABASE');
$GLOBALS['mysqlHeatpumpTable'] = env('MYSQL_HEATPUMP_TABLE', 'powerlog');
$GLOBALS['mysqlHousePowerTable'] = env('MYSQL_HOUSEPOWERLOG_TABLE', 'powerloghus');
$GLOBALS['heatpumpSyncInterval'] = env('HEATPUMP_SYNC_INTERVAL', '300'); // Default: 5 minutes

// Electricity cost settings — spot price area and grid operator GLN
$GLOBALS['elspotArea'] = env('ELSPOT_AREA', 'DK2');
$GLOBALS['elspotGln']  = env('ELSPOT_GLN',  '');

// Weather data — GPS coordinates for degree-day (HDD) calculation via Open-Meteo
$GLOBALS['weatherLat'] = env('WEATHER_LAT', '');
$GLOBALS['weatherLon'] = env('WEATHER_LON', '');

// Timezone Configuration
$timezone = env('TIMEZONE', 'Europe/Copenhagen');
date_default_timezone_set($timezone);

// Cache directory — override with CACHE_DIR in .env if /tmp is volatile on your host
$GLOBALS['cacheDir'] = rtrim(env('CACHE_DIR', sys_get_temp_dir()), '/\\');

// Admin key — required to access privileged endpoints (e.g. getSystemLog.php)
// Set a long random string in .env: ADMIN_KEY=<random>
$GLOBALS['adminKey'] = env('ADMIN_KEY', '');

// Vehicle telemetry API key — required by receive_vehicle_data.php
// Set in .env: VEHICLE_API_KEY=<random>  and configure the same value in Home Assistant
$GLOBALS['vehicleApiKey'] = env('VEHICLE_API_KEY', '');

// Debug Mode
$debugMode = env('DEBUG', 'false') === 'true';

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