<?php
/**
 * Small HTTP helpers shared by the API routes: the error exception and the parameter validators. Reading the
 * request is Request's job (query, body(), header()), and sending the answer is Response's.
 *
 * Error envelope (all routes): {"success": false, "error": "<message>"}
 */
final class Http
{
    /**
     * Stop with an error: throws HttpException, which Router turns into the error envelope
     * {"success": false, "error": "<message>"} with this status (and headers).
     */
    public static function fail(string $message, int $status = 400, array $headers = []): never
    {
        throw new HttpException($message, $status, $headers);
    }

    /** Integer from an array (body / query), 0 when missing. */
    public static function int(array $source, string $key, int $default = 0): int
    {
        return isset($source[$key]) ? (int) $source[$key] : $default;
    }

    /** Trimmed string from an array (body), '' when missing. */
    public static function str(array $source, string $key, string $default = ''): string
    {
        return isset($source[$key]) ? trim((string) $source[$key]) : $default;
    }

    // ── Validators ──────────────────────────────────────────────────────────────

    /** YYYY-MM-DD and a real calendar date. */
    public static function isDate(string $v): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** YYYY-MM. */
    public static function isMonth(string $v): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v);
    }

    /** YYYY. */
    public static function isYear(string $v): bool
    {
        return (bool) preg_match('/^\d{4}$/', $v);
    }
}
