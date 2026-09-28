<?php
/**
 * Vehicle telemetry endpoint — standalone file for Authentik bypass.
 *
 * This file exists separately from the api.php router because it needs
 * to be excluded from the Authentik auth proxy. HA calls this directly.
 * The actual handler logic lives in routes/receive_vehicle_data.php.
 */
require __DIR__ . '/includes/configuration.php';
require __DIR__ . '/includes/DatabaseManager.php';
require __DIR__ . '/includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
    require __DIR__ . '/routes/receive_vehicle_data.php';
} catch (PDOException $e) {
    http_response_code(500);
    error_log("receive_vehicle_data.php DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("receive_vehicle_data.php error: " . $e->getMessage());
    // Never leak internal messages/paths to the client in production.
    $clientError = Config::debug() ? $e->getMessage() : 'Der opstod en intern fejl';
    echo json_encode(['success' => false, 'error' => $clientError]);
}
