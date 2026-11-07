<?php
/** Monta answered with something the sync cannot work with (bad credentials, HTTP 4xx/5xx after retries). */
final class MontaApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode = 0)
    {
        parent::__construct($message);
    }
}
