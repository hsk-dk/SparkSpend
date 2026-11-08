<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Health check for an uptime monitor.
 *
 * 200 {"status":"ok",...}        every source has fresh data
 * 503 {"status":"degraded",...}  at least one source is stale, errored or has never delivered
 * 500                            the databases cannot be read (Router::run)
 * 401 / 503                      wrong / no key (HEALTH_KEY, fail closed)
 *
 * See includes/Freshness.php for what is checked and how old is too old. Not cached: the point is
 * to see the situation now.
 */
require_once __DIR__ . '/../includes/ApiAuth.php';

return static function (Request $request): Response {
    ApiAuth::requireBearer(Config::healthKey(), 'HEALTH_KEY', $request);
    if (!in_array($request->method, ['GET', 'HEAD'], true)) {
        Http::fail('Method not allowed', 405);
    }

    $result = Freshness::collect(
        DatabaseManager::getChargesDb(),
        DatabaseManager::getPowerlogDb(),
        Config::cacheDir(),
        time()
    );
    return Response::json($result, $result['status'] !== 'ok' ? 503 : null);
};
