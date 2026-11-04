<?php
/**
 * Small HTTP helpers shared by the API routes: one error envelope, one way to read the
 * request, one set of parameter validators. Replaces the copies of
 *   http_response_code(...); echo json_encode(['success' => false, ...]); exit;
 *   json_decode(file_get_contents('php://input'), true)
 *   preg_match('/^\d{4}-\d{2}-\d{2}$/', ...)
 * that were repeated in 30 route files.
 *
 * Error envelope (all routes): {"success": false, "error": "<message>"}
 */
final class Http
{
    /** Send a JSON answer (status code + body). Does not exit: routes end after it anyway. */
    public static function send(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        echo is_string($data) ? $data : json_encode($data);
    }

    /** Send the error envelope and stop. */
    public static function fail(string $message, int $status = 400, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        foreach ($headers as $name => $value) header("$name: $value");
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    /**
     * The request body as an array: a JSON object body, or the form fields when the request
     * was sent as a form. Returns [] when there is no (valid) body.
     */
    public static function body(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_contains($contentType, 'application/json') && $_POST) return $_POST;
        $raw = file_get_contents('php://input');
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** Like body(), but a missing/invalid JSON object is a 400 error. */
    public static function requireBody(string $message = 'Invalid JSON format'): array
    {
        $data = self::body();
        if ($data === []) self::fail($message, 400);
        return $data;
    }

    /** Trimmed query-string value ('' when missing or not a string). */
    public static function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
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
