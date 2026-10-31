<?php
/**
 * Charger event endpoint — standalone file for Authentik bypass.
 *
 * Same reason as receive_vehicle_data.php: Home Assistant calls this directly, so
 * the path must be excluded from the Authentik auth proxy. The handler logic
 * lives in routes/receive_charger_event.php.
 */
require __DIR__ . '/includes/configuration.php';
require __DIR__ . '/includes/DatabaseManager.php';
require __DIR__ . '/includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
    require __DIR__ . '/routes/receive_charger_event.php';
} catch (PDOException $e) {
    http_response_code(500);
    error_log("receive_charger_event.php DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("receive_charger_event.php error: " . $e->getMessage());
    $clientError = Config::debug() ? $e->getMessage() : 'Der opstod en intern fejl';
    echo json_encode(['success' => false, 'error' => $clientError]);
}
