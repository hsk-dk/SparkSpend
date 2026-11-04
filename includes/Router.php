<?php
/**
 * Dispatches an API request to its route file: method check, CSRF check, error handling.
 *
 * A route is  'action' => ['handler' => 'routes/x.php', 'method' => 'GET'|'POST', 'csrf' => bool]
 * (see includes/routes.php). A POST route is never reachable with GET - that also closes the
 * hole where a state-changing route skipped CSRF verification because the verifier only
 * looks at POST requests.
 */
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/ApiCache.php';

final class Router
{
    /** @param array<string,array> $routes */
    public static function dispatch(array $routes, string $action): void
    {
        if (!isset($routes[$action])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
            return;
        }
        $route  = $routes[$action];
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $allowed = $route['method'] ?? 'GET';
        if ($method !== $allowed) {
            Http::fail('Method not allowed', 405, ['Allow' => $allowed]);
        }
        if (!empty($route['csrf'])) {
            csrfVerify();
        }
        self::run($route['handler'], $action);
    }

    /** Runs a route file with the shared error handling (never leaks internals unless DEBUG). */
    public static function run(string $handlerFile, string $label): void
    {
        try {
            require dirname(__DIR__) . '/' . $handlerFile;
        } catch (PDOException $e) {
            http_response_code(500);
            error_log("API [{$label}] DB error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error occurred']);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log("API [{$label}] error: " . $e->getMessage());
            // Full detail is in the error log; enable DEBUG in .env to surface it.
            $clientError = Config::debug() ? $e->getMessage() : 'Der opstod en intern fejl';
            echo json_encode(['success' => false, 'error' => $clientError]);
        }
    }
}
