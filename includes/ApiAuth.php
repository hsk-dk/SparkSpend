<?php
/**
 * Bearer-token authentication for the machine endpoints that bypass the auth proxy
 * (Home Assistant -> receive_vehicle_data / receive_charger_event).
 *
 * Fails closed: without a configured key the endpoint answers 503, never "open".
 * The comparison is constant-time (hash_equals).
 */
final class ApiAuth
{
    /** Ends the request with 503/401 unless "Authorization: Bearer <key>" matches the key. */
    public static function requireBearer(string $configuredKey, string $configName = 'VEHICLE_API_KEY'): void
    {
        if ($configuredKey === '') {
            self::fail(503, ['success' => false, 'error' => "Endpoint not configured — set {$configName} in .env"]);
        }
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ') || !hash_equals($configuredKey, substr($header, 7))) {
            header('WWW-Authenticate: Bearer realm="SparkSpend"');
            self::fail(401, ['success' => false, 'error' => 'Unauthorized']);
        }
    }

    private static function fail(int $code, array $body): never
    {
        http_response_code($code);
        echo json_encode($body);
        exit;
    }
}
