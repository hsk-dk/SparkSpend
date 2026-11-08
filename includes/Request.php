<?php
/**
 * What a route handler sees of the HTTP request, instead of reaching for $_GET / $_SERVER / php://input.
 * Built once per request by Request::fromGlobals(); a test builds one with Request::make().
 *
 *   $request->query['dateRange']        the query string (as $_GET)
 *   $request->param('filter')           a trimmed string from the query ('' when missing)
 *   $request->body()                    JSON object body, or the form fields; [] when there is none
 *   $request->header('Authorization')   a request header ('' when missing)
 */
final class Request
{
    private ?string $raw;

    /**
     * @param array<string,mixed> $query   as $_GET
     * @param array<string,mixed> $post    as $_POST
     * @param array<string,mixed> $server  as $_SERVER
     * @param array<string,mixed> $session as $_SESSION
     * @param string|null         $raw     the raw body; null = read php://input on first use
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $session = [],
        ?string $raw = null,
    ) {
        $this->raw = $raw;
    }

    public static function fromGlobals(): self
    {
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $_GET,
            $_POST,
            $_SERVER,
            $_SESSION ?? [],
        );
    }

    /** For tests: a request with the given parts. */
    public static function make(string $method = 'GET', array $query = [], array $body = [], array $headers = [], array $session = []): self
    {
        $server = ['REQUEST_METHOD' => $method];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }
        if ($body !== [] && !isset($server['CONTENT_TYPE'])) $server['CONTENT_TYPE'] = 'application/json';
        return new self(strtoupper($method), $query, [], $server, $session, $body === [] ? '' : json_encode($body));
    }

    /** Trimmed query-string value ('' - or $default - when missing or not a string). Same rule as the old Http::query(). */
    public function param(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public function raw(): string
    {
        if ($this->raw === null) {
            $in = file_get_contents('php://input');
            $this->raw = is_string($in) ? $in : '';
        }
        return $this->raw;
    }

    /**
     * The request body as an array: a JSON object body, or the form fields when the request was sent as a form.
     * [] when there is no (valid) body. (Same rule as the old Http::body().)
     */
    public function body(): array
    {
        $contentType = (string) ($this->server['CONTENT_TYPE'] ?? '');
        if (!str_contains($contentType, 'application/json') && $this->post) return $this->post;
        $raw = $this->raw();
        $data = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** Like body(), but a missing or invalid JSON object is a 400. */
    public function requireBody(string $message = 'Invalid JSON format'): array
    {
        $data = $this->body();
        if ($data === []) Http::fail($message, 400);
        return $data;
    }

    /** A request header by name ('Authorization', 'X-CSRF-Token'); '' when missing. */
    public function header(string $name): string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? $this->server['HTTP_' . $key] ?? '');
    }
}
