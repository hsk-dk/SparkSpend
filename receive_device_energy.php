<?php
/**
 * Device energy endpoint - standalone file for the Authentik bypass (Home Assistant calls it
 * directly, so this path must be excluded from the auth proxy like receive_charger_event.php).
 * The handler lives in routes/receive_device_energy.php.
 */
require __DIR__ . '/includes/configuration.php';
require __DIR__ . '/includes/DatabaseManager.php';
require __DIR__ . '/includes/Router.php';

header('Content-Type: application/json');

Router::run('routes/receive_device_energy.php', 'receive_device_energy.php');
