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
require __DIR__ . '/includes/Router.php';

header('Content-Type: application/json');

Router::run('routes/receive_charger_event.php', 'receive_charger_event.php');
