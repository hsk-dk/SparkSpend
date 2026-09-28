<?php
/**
 * File-based JSON Cache Helper
 *
 * Provides simple file-based caching with TTL and pattern-based invalidation.
 * Cache files are stored in the system temp dir (or CACHE_DIR from .env).
 */

require_once __DIR__ . '/Config.php';

class CacheHelper {

    // ── Standard cache lifetimes (seconds) ──────────────────────────────────
    // Use these instead of scattering magic TTL numbers across route handlers.
    /** Volatile data that changes often (e.g. sync status). */
    public const TTL_SHORT  = 30;
    /** Derived stats that tolerate a few minutes of staleness. */
    public const TTL_MEDIUM = 300;   // 5 minutes
    /** Heavy aggregates / meter data refreshed at most hourly. */
    public const TTL_LONG   = 3600;  // 1 hour

    /**
     * Read from the shared JSON file cache.
     *
     * @param string $key  Cache identifier (e.g. 'dashboard_2025-07-12')
     * @param int    $ttl  Maximum age in seconds.
     * @return string|null Cached JSON string, or null on cache miss.
     */
    public static function read(string $key, int $ttl): ?string {
        $dir  = Config::cacheDir();
        $file = $dir . DIRECTORY_SEPARATOR . 'sparkspend_' . $key . '.json';
        if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
            $content = file_get_contents($file);
            return $content !== false ? $content : null;
        }
        return null;
    }

    /**
     * Write a JSON string to the shared file cache.
     *
     * @param string $key  Cache identifier.
     * @param string $json The JSON string to cache.
     */
    public static function write(string $key, string $json): void {
        $dir  = Config::cacheDir();
        $file = $dir . DIRECTORY_SEPARATOR . 'sparkspend_' . $key . '.json';
        $handle = @fopen($file, 'c');
        if ($handle === false) return;
        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    /**
     * Cache key prefixes affected by a change to charge data (internal/external).
     * Single source of truth — used by invalidateChargeCaches().
     */
    public const CHARGE_CACHE_PREFIXES = [
        'dashboard_', 'ev_compare_', 'annual_', 'analytics_',
        'bill_', 'efficiency_', 'providerstats_', 'vehicle_compare_',
    ];

    /**
     * Cache key prefixes affected by a change to provider data.
     */
    public const PROVIDER_CACHE_PREFIXES = ['providerstats_'];

    /**
     * Cache key prefixes affected by a meter/consumption sync
     * (heat pump + house power). Also covers the aggregate/dashboard views
     * that combine meter data, so freshly synced kWh shows up immediately.
     */
    public const METER_CACHE_PREFIXES = [
        'heatpump_', 'housepower_', 'dashboard_', 'annual_', 'anomaly_', 'bill_',
    ];

    /**
     * Delete all cache files whose key starts with the given prefix.
     *
     * @param string $prefix The key prefix to match (e.g. 'dashboard_').
     */
    public static function invalidatePattern(string $prefix): void {
        $dir     = Config::cacheDir();
        $pattern = $dir . DIRECTORY_SEPARATOR . 'sparkspend_' . $prefix . '*.json';
        foreach (glob($pattern) ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Invalidate every cache prefix listed in the given set.
     *
     * @param string[] $prefixes List of key prefixes.
     */
    public static function invalidatePrefixes(array $prefixes): void {
        foreach ($prefixes as $prefix) {
            self::invalidatePattern($prefix);
        }
    }

    /**
     * Invalidate all caches that depend on charge data. Call from any endpoint
     * that creates/updates/deletes internal or external charges, so the list of
     * affected caches lives in exactly one place.
     */
    public static function invalidateChargeCaches(): void {
        self::invalidatePrefixes(self::CHARGE_CACHE_PREFIXES);
    }

    /**
     * Invalidate all caches that depend on provider data.
     */
    public static function invalidateProviderCaches(): void {
        self::invalidatePrefixes(self::PROVIDER_CACHE_PREFIXES);
    }

    /**
     * Invalidate all caches that depend on meter/consumption data. Call after a
     * heat pump / house power sync so newly synced kWh isn't hidden behind the
     * hourly TTL.
     */
    public static function invalidateMeterCaches(): void {
        self::invalidatePrefixes(self::METER_CACHE_PREFIXES);
    }
}
