<?php
/**
 * SparkSpend API Router
 *
 * Single entry point for all JSON API requests.
 * Centralizes: bootstrap, JSON header, CSRF verification, error handling.
 *
 * Usage:
 *   api.php?action=charges&filter=all
 *   api.php?action=create-ext-charge  (POST)
 *
 * Legacy endpoint files (getCharges.php, etc.) are thin shims that delegate here.
 */

require __DIR__ . '/includes/configuration.php';
require __DIR__ . '/includes/DatabaseManager.php';
require __DIR__ . '/includes/QueryBuilder.php';

header('Content-Type: application/json');

// ── Route Table ──────────────────────────────────────────────────────────────
$routes = [
    // GET — read endpoints
    'dashboard'            => ['handler' => 'routes/get_dashboard_summary.php'],
    'charges'              => ['handler' => 'routes/get_charges.php'],
    'charge-analytics'     => ['handler' => 'routes/get_charge_analytics.php'],
    'charge-compare'       => ['handler' => 'routes/get_charge_compare.php'],
    'annual'               => ['handler' => 'routes/get_annual_summary.php'],
    'anomalies'            => ['handler' => 'routes/get_anomaly_stats.php'],
    'efficiency'           => ['handler' => 'routes/get_efficiency_stats.php'],
    'elspot-prices'        => ['handler' => 'routes/get_elspot_prices.php'],
    'heatpump'             => ['handler' => 'routes/get_heatpump_data.php'],
    'house-power'          => ['handler' => 'routes/get_house_power_data.php'],
    'monthly-bill'         => ['handler' => 'routes/get_monthly_bill_data.php'],
    'providers'            => ['handler' => 'routes/get_providers.php'],
    'provider-stats'       => ['handler' => 'routes/get_provider_stats.php'],
    'sync-status'          => ['handler' => 'routes/get_sync_status.php'],
    'system-log'           => ['handler' => 'routes/get_system_log.php'],
    'vehicle-comparison'   => ['handler' => 'routes/get_vehicle_comparison.php'],
    'vehicles'             => ['handler' => 'routes/get_vehicles.php'],
    'weather'              => ['handler' => 'routes/get_weather_data.php'],

    // POST — mutation endpoints (CSRF required)
    'create-ext-charge'    => ['handler' => 'routes/create_ext_charge.php',    'csrf' => true],
    'create-provider'      => ['handler' => 'routes/create_provider.php',      'csrf' => true],
    'delete-ext-charge'    => ['handler' => 'routes/delete_ext_charge.php',    'csrf' => true],
    'delete-provider'      => ['handler' => 'routes/delete_provider.php',      'csrf' => true],
    'update-ext-charge'    => ['handler' => 'routes/update_ext_charge.php',    'csrf' => true],
    'update-internal-charge' => ['handler' => 'routes/update_internal_charge.php', 'csrf' => true],
    'update-provider'      => ['handler' => 'routes/update_provider.php',      'csrf' => true],
    'update-vehicle'       => ['handler' => 'routes/update_vehicle.php',       'csrf' => true],
    'trigger-sync'         => ['handler' => 'routes/trigger_sync.php',         'csrf' => true],

    // POST — external API (API key auth, no CSRF)
    'receive-vehicle-data' => ['handler' => 'routes/receive_vehicle_data.php', 'csrf' => false],
];

// ── Dispatch ─────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!isset($routes[$action])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Unknown action: {$action}"]);
    exit;
}

$route = $routes[$action];

// CSRF verification for mutation endpoints
if (!empty($route['csrf'])) {
    csrfVerify();
}

// Execute handler with centralized error handling
try {
    require __DIR__ . '/' . $route['handler'];
} catch (PDOException $e) {
    http_response_code(500);
    error_log("API [{$action}] DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("API [{$action}] error: " . $e->getMessage());
    // Never leak internal messages/paths to the client in production.
    // Full detail is in the error log; enable DEBUG in .env to surface it.
    $clientError = Config::debug() ? $e->getMessage() : 'Der opstod en intern fejl';
    echo json_encode(['success' => false, 'error' => $clientError]);
}
