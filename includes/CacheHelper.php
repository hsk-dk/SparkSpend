<?php
/**
 * File-based JSON Cache Helper
 *
 * Provides simple file-based caching with TTL and pattern-based invalidation.
 * Cache files are stored in the system temp dir (or CACHE_DIR from .env).
 */

class CacheHelper {

    /**
     * Read from the shared JSON file cache.
     *
     * @param string $key  Cache identifier (e.g. 'dashboard_2025-07-12')
     * @param int    $ttl  Maximum age in seconds.
     * @return string|null Cached JSON string, or null on cache miss.
     */
    public static function read(string $key, int $ttl): ?string {
        $dir  = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
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
        $dir  = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
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
     * Delete all cache files whose key starts with the given prefix.
     *
     * @param string $prefix The key prefix to match (e.g. 'dashboard_').
     */
    public static function invalidatePattern(string $prefix): void {
        $dir     = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
        $pattern = $dir . DIRECTORY_SEPARATOR . 'sparkspend_' . $prefix . '*.json';
        foreach (glob($pattern) ?: [] as $file) {
            @unlink($file);
        }
    }
}
