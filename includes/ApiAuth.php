<?php
/**
 * Bearer-token authentication for the machine endpoints that bypass the auth proxy
 * (Home Assistant -> receive_vehicle_data / receive_charger_event).
 *
 * Fails closed: without a configured key the endpoint answers 503, never "open". Both failures throw
 * HttpException, which Router turns into the error envelope.
 * The comparison is constant-time (hash_equals).
 */
final class ApiAuth
{
    /** Ends the request with 503/401 unless "Authorization: Bearer <key>" matches the key. */
    public static function requireBearer(string $configuredKey, string $configName = 'VEHICLE_API_KEY', ?Request $request = null): void
    {
        if ($configuredKey === '') {
            throw new HttpException("Endpoint not configured — set {$configName} in .env", 503);
        }
        $header = $request !== null ? $request->header('Authorization') : ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!str_starts_with($header, 'Bearer ') || !hash_equals($configuredKey, substr($header, 7))) {
            throw new HttpException('Unauthorized', 401, ['WWW-Authenticate' => 'Bearer realm="SparkSpend"']);
        }
    }
}
