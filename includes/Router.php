<?php
/**
 * Dispatches an API request to its route file: method check, CSRF check, error handling.
 *
 * A route is  'action' => ['handler' => 'routes/x.php', 'method' => 'GET'|'POST', 'csrf' => bool]
 * (see includes/routes.php). A POST route is never reachable with GET - that also closes the
 * hole where a state-changing route skipped CSRF verification because the verifier only
 * looks at POST requests.
 *
 * A route FILE returns a handler:
 *
 *     return static function (Request $request): Response|array|null { ... };
 *
 * It reads the request from $request (not from $_GET / php://input) and returns a Response (or an array, sent as
 * JSON). It fails with Http::fail(), which throws HttpException. Nothing in it echoes or exits, so Router::handle()
 * can call it in a test and look at the answer. (A file that returns nothing is a legacy script route: it has
 * already written its output when it was included.)
 */
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/ApiCache.php';

final class Router
{
    /** @var array<string,\Closure> the handlers already loaded (a route file is included once per process) */
    private static array $handlers = [];

    /** @param array<string,array> $routes */
    public static function dispatch(array $routes, string $action): void
    {
        try {
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
        } catch (HttpException $e) {
            self::sendError($e);
            return;
        }
        self::run($route['handler'], $action);
    }

    /** Runs a route file with the shared error handling (never leaks internals unless DEBUG). */
    public static function run(string $handlerFile, string $label, ?Request $request = null): void
    {
        try {
            $response = self::handle($handlerFile, $request ?? Request::fromGlobals());
            $response?->send();
        } catch (HttpException $e) {
            self::sendError($e);
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

    /**
     * Calls the handler in a route file and returns its Response, without sending anything. Errors propagate
     * (HttpException for Http::fail, anything else as thrown). Null = a legacy script route, already written.
     */
    public static function handle(string $handlerFile, Request $request): ?Response
    {
        $handler = self::$handlers[$handlerFile] ?? null;
        if ($handler === null) {
            $loaded = require dirname(__DIR__) . '/' . $handlerFile;
            if (!$loaded instanceof \Closure) return null;
            $handler = self::$handlers[$handlerFile] = $loaded;
        }
        $result = $handler($request);
        if ($result === null || $result instanceof Response) return $result;
        return Response::json($result);
    }

    private static function sendError(HttpException $e): void
    {
        http_response_code($e->status);
        header('Content-Type: application/json');
        foreach ($e->headers as $name => $value) header("$name: $value");
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
