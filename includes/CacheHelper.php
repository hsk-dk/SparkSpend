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

    // -- What each cached view depends on ---------------------------------------------------------
    //
    // The single source of truth for cache invalidation. Every cached key starts with one of these
    // prefixes; its tags say which kinds of data the view is built from. A writer (a route, a cron job)
    // names what it changed - CacheHelper::invalidate(['charges']) - and every view with that tag goes.
    // Nobody keeps a list of "which caches does my change affect": that list used to be kept by hand per
    // writer, and it was wrong in five places (house power, base load and the charge optimiser did not
    // follow charge changes; efficiency did not follow odometer readings; charger events refreshed nothing).
    //
    // A key whose prefix is not here is NOT cached (ApiCache fails safe), and dev/test_cache_deps.php
    // fails if a route caches under an unregistered prefix, or a writer does not invalidate its tag.

    /** The kinds of data a view can depend on, and who changes them. */
    public const TAGS = [
        'charges'   => 'charges and external charges: Monta sync, create/update/delete charge',
        'providers' => 'charging providers (names, prices)',
        'vehicles'  => 'vehicle names',
        'meter'     => 'heat pump / house meter and per-device energy: MeterSync, receive_device_energy',
        'ha'        => 'what Home Assistant pushes: charger events, odometer and plug-in readings',
        'prices'    => 'electricity prices / tariffs (cron/warm_prices.php)',
        'sync'      => 'sync status: written by every cron job run',
    ];

    /** key prefix => tags */
    public const DEPENDENCIES = [
        'dashboard_'       => ['charges', 'meter', 'prices'],
        'annual_'          => ['charges', 'meter', 'prices'],
        'anomaly_'         => ['charges', 'meter'],
        'baseload_'        => ['charges', 'meter', 'ha'],
        'analytics_'       => ['charges', 'providers', 'vehicles'],
        'ev_compare_'      => ['charges'],
        'chargeopt_'       => ['charges', 'ha', 'prices'],
        'efficiency_'      => ['charges', 'ha'],
        'heatpump_'        => ['meter'],
        'hourlycons_'      => ['charges', 'meter', 'ha'],
        'housepower_'      => ['charges', 'meter', 'ha', 'prices'],
        'bill_'            => ['charges', 'meter', 'ha', 'prices'],
        'providerstats_'   => ['charges', 'providers'],
        'vehicle_compare_' => ['charges', 'vehicles'],
        'syncstatus'       => ['sync'],
    ];

    /** Tags of the registered prefix this key starts with (longest match), or null = not registered. */
    public static function dependenciesOf(string $key): ?array {
        $best = null;
        foreach (self::DEPENDENCIES as $prefix => $tags) {
            if (str_starts_with($key, $prefix) && ($best === null || strlen($prefix) > strlen($best))) $best = $prefix;
        }
        return $best === null ? null : self::DEPENDENCIES[$best];
    }

    /** Prefixes of every view that depends on at least one of the tags. @return string[] */
    public static function prefixesFor(array $tags): array {
        foreach ($tags as $t) {
            if (!isset(self::TAGS[$t])) throw new InvalidArgumentException("Unknown cache tag: {$t}");
        }
        return array_keys(array_filter(self::DEPENDENCIES, fn($deps) => array_intersect($deps, $tags) !== []));
    }

    /**
     * Drop every cached view that depends on any of the tags. Call it with what you CHANGED.
     * An unknown tag throws (a typo must not silently invalidate nothing).
     *
     * @param string[] $tags keys of self::TAGS
     */
    public static function invalidate(array $tags): void {
        self::invalidatePrefixes(self::prefixesFor($tags));
    }

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

    /** @param string[] $prefixes */
    public static function invalidatePrefixes(array $prefixes): void {
        foreach ($prefixes as $prefix) {
            self::invalidatePattern($prefix);
        }
    }

    /**
     * Removes cache files nobody will read again. Keys carry the date (dashboard_2026-11-07) or the hour
     * (chargeopt_..._2026-11-07-13_...), so without this every day leaves files behind for good. Raw
     * Energi Data Service files are kept longer: they are the fallback when the upstream is unavailable
     * (ElspotPrices serves them up to EDS_STALE_MAX = 7 days).
     *
     * @return int files removed
     */
    public static function purgeExpired(int $maxAgeJson = 2 * 86400, int $maxAgeRaw = 14 * 86400, ?int $now = null): int {
        $now ??= time();
        $dir = Config::cacheDir();
        $removed = 0;
        foreach ([['json', $maxAgeJson], ['dat', $maxAgeRaw]] as [$ext, $maxAge]) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . 'sparkspend_*.' . $ext) ?: [] as $file) {
                if (str_ends_with($file, 'eds_backoff.dat')) continue;   // a marker, rewritten as needed
                $m = @filemtime($file);
                if ($m !== false && ($now - $m) > $maxAge && @unlink($file)) $removed++;
            }
        }
        return $removed;
    }
}
