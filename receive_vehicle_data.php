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
require __DIR__ . '/includes/Router.php';

header('Content-Type: application/json');

Router::run('routes/receive_vehicle_data.php', 'receive_vehicle_data.php');
