<?php
/**
 * Prints DashboardSummary::build() for the databases named in the env file (SPARKSPEND_ENV_FILE), as JSON.
 * Run under a frozen clock (libfaketime) to get byte-identical output for the same data:
 *
 *   faketime '2026-11-08 12:00:00' php dev/golden/dashboard_snapshot.php
 *
 * dev/golden/capture.sh does that for several instants (month change, short February, DST, new year) and
 * several databases, so a refactoring of the dashboard can be proved to change nothing: capture before,
 * change the code, capture after, diff.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/includes/configuration.php';
require dirname(__DIR__, 2) . '/includes/DatabaseManager.php';

echo json_encode(
    DashboardSummary::build(DatabaseManager::getChargesDb(), DatabaseManager::getPowerlogDb()),
    JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE
), "\n";
