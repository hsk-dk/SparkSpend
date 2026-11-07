<?php
/**
 * Health endpoint - standalone file so it can be excluded from the Authentik proxy (an uptime monitor
 * such as Uptime Kuma calls it directly, with "Authorization: Bearer <HEALTH_KEY>").
 * The handler lives in routes/health.php.
 */
require __DIR__ . '/includes/configuration.php';
require __DIR__ . '/includes/DatabaseManager.php';
require __DIR__ . '/includes/Router.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

Router::run('routes/health.php', 'health');
