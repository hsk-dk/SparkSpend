<?php
/**
 * Response cache for the API routes (file cache, see CacheHelper). Replaces the
 * read-echo-exit / write-echo pattern each cached route spelled out by hand:
 *
 *   ApiCache::serve('dashboard_' . date('Y-m-d'), CacheHelper::TTL_MEDIUM);   // hit -> answers and exits
 *   ... build $payload ...
 *   ApiCache::respond($key, $payload);                                          // stores and answers
 *
 * A payload that is not complete (e.g. built while an upstream was rate-limited) is
 * answered but not stored: pass $cache = false.
 */
require_once __DIR__ . '/CacheHelper.php';

final class ApiCache
{
    /** If a fresh cached answer exists: send it and exit. */
    public static function serve(string $key, int $ttl): void
    {
        $cached = CacheHelper::read($key, $ttl);
        if ($cached !== null) { echo $cached; exit; }
    }

    /** Encode (unless already a JSON string), store when $cache, and send. */
    public static function respond(string $key, mixed $payload, bool $cache = true): void
    {
        $json = is_string($payload) ? $payload : json_encode($payload);
        if ($cache) CacheHelper::write($key, $json);
        echo $json;
    }
}
