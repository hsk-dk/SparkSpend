<?php
/**
 * What a route handler returns: a status, headers and a body. Router::run() sends it. Handlers build one with
 * Response::json() instead of echoing, so a handler can be called and its answer inspected.
 *
 * A handler may also return a plain array (sent as JSON, 200) or a JSON string.
 */
final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        public readonly string $body,
        public readonly ?int $status,
        public readonly array $headers,
    ) {}

    /** JSON answer. A string is taken as already-encoded JSON (e.g. from the cache). Status null = leave as is (200). */
    public static function json(mixed $data, ?int $status = null, array $headers = []): self
    {
        return new self(is_string($data) ? $data : (string) json_encode($data), $status, $headers);
    }

    public static function text(string $body, ?int $status = null, array $headers = []): self
    {
        return new self($body, $status, $headers);
    }

    /** The decoded JSON body (for tests). */
    public function data(): mixed
    {
        return json_decode($this->body, true);
    }

    public function send(): void
    {
        if ($this->status !== null) http_response_code($this->status);
        foreach ($this->headers as $name => $value) header("$name: $value");
        echo $this->body;
    }
}
