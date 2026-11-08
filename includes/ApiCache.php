<?php
/**
 * Response cache for the API routes (file cache, see CacheHelper). A cached route does
 *
 *   $key = 'dashboard_' . date('Y-m-d');
 *   if (($hit = ApiCache::fetch($key, CacheHelper::TTL_MEDIUM)) !== null) return Response::json($hit);
 *   ... build $payload ...
 *   return ApiCache::store($key, $payload);
 *
 * Nothing echoes or exits, so a handler can be called and its answer inspected. A payload that is not complete
 * (e.g. built while an upstream was rate-limited) is answered but not stored: pass $cache = false.
 */
require_once __DIR__ . '/CacheHelper.php';

final class ApiCache
{
    /** A fresh cached answer (JSON text), or null. */
    public static function fetch(string $key, int $ttl): ?string
    {
        return self::cacheable($key) ? CacheHelper::read($key, $ttl) : null;
    }

    /** Encode (unless already a JSON string), store when $cache, and return it as the answer. */
    public static function store(string $key, mixed $payload, bool $cache = true): Response
    {
        $json = is_string($payload) ? $payload : (string) json_encode($payload);
        if ($cache && self::cacheable($key)) CacheHelper::write($key, $json);
        return Response::json($json);
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
}
