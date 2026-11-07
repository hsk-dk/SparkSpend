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
        if (!self::cacheable($key)) return;
        $cached = CacheHelper::read($key, $ttl);
        if ($cached !== null) { echo $cached; exit; }
    }

    /**
     * A key is cached only if its prefix is registered in CacheHelper::DEPENDENCIES: otherwise nothing
     * would ever invalidate it, and a view that is not told what it depends on must not be cached.
     */
    private static function cacheable(string $key): bool
    {
        if (CacheHelper::dependenciesOf($key) !== null) return true;
        error_log("ApiCache: '{$key}' is not registered in CacheHelper::DEPENDENCIES - not cached.");
        return false;
    }

    /** Encode (unless already a JSON string), store when $cache, and send. */
    public static function respond(string $key, mixed $payload, bool $cache = true): void
    {
        $json = is_string($payload) ? $payload : json_encode($payload);
        if ($cache && self::cacheable($key)) CacheHelper::write($key, $json);
        echo $json;
    }
}
