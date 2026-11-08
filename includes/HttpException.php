<?php
/**
 * "Stop here with this HTTP error": thrown by Http::fail() and ApiAuth, caught by Router, which turns it into
 * the error envelope {"success": false, "error": "<message>"}. It replaces `echo ...; exit;`, so a route is a
 * function that can be called (and its failures asserted) without ending the process.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string,string> $headers extra response headers (e.g. Allow, WWW-Authenticate) */
    public function __construct(string $message, public readonly int $status = 400, public readonly array $headers = [])
    {
        parent::__construct($message, $status);
    }
}
