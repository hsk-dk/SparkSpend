<?php
/**
 * SparkSpend API Router
 *
 * Single entry point for all JSON API requests.
 * Centralizes: bootstrap, JSON header and dispatch (method check, CSRF, error handling).
 *
 * Usage:
 *   api.php?action=charges&filter=all
 *   api.php?action=create-ext-charge  (POST)
 *
 * Standalone entry points (receive_vehicle_data.php, receive_charger_event.php) exist
 * only so the Home Assistant calls can bypass the auth proxy; everything else goes
 * through this router.
 */

require __DIR__ . '/includes/configuration.php';
require __DIR__ . '/includes/DatabaseManager.php';
require __DIR__ . '/includes/QueryBuilder.php';
require __DIR__ . '/includes/Router.php';

header('Content-Type: application/json');

// The route table is includes/routes.php; Router handles 404/405, CSRF and error handling.
Router::dispatch(require __DIR__ . '/includes/routes.php', (string) ($_GET['action'] ?? $_POST['action'] ?? ''));
