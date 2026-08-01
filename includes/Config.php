<?php
/**
 * SparkSpend Application Configuration
 *
 * Centralized, typed access to all configuration values loaded from .env.
 * Replaces scattered $GLOBALS[] usage with a clean static API.
 *
 * Usage:
 *   Config::cacheDir()              // typed shortcut
 *   Config::get('elspotArea')       // generic getter
 *   Config::get('someKey', 'default')
 *
 * For testing:
 *   Config::override(['cacheDir' => '/tmp/test']);
 *   Config::reset();
 */

class Config {
    private static array $values = [];
    private static bool $loaded = false;

    /**
     * Load all configuration values from environment.
     * Called once during bootstrap (configuration.php).
     */
    public static function load(): void {
        if (self::$loaded) return;
        self::$loaded = true;

        self::$values = [
            // Database paths
            'dbPath'              => env('CHARGING_DB_PATH', 'data/charging_data.db'),
            'powerlogDbPath'      => env('POWERLOG_DB_PATH', 'data/powerlog_data.db'),

            // MySQL heat pump sync source
            'mysqlHeatpumpHost'     => env('MYSQL_HEATPUMP_HOST'),
            'mysqlHeatpumpPort'     => env('MYSQL_HEATPUMP_PORT', '3306'),
            'mysqlHeatpumpUser'     => env('MYSQL_HEATPUMP_USER'),
            'mysqlHeatpumpPassword' => env('MYSQL_HEATPUMP_PASSWORD', ''),
            'mysqlHeatpumpDatabase' => env('MYSQL_HEATPUMP_DATABASE'),
            'mysqlHeatpumpTable'    => env('MYSQL_HEATPUMP_TABLE', 'powerlog'),
            'mysqlHousePowerTable'  => env('MYSQL_HOUSEPOWERLOG_TABLE', 'powerloghus'),
            'heatpumpSyncInterval'  => env('HEATPUMP_SYNC_INTERVAL', '300'),

            // Monta API credentials
            'montaClientId'     => env('MONTA_CLIENT_ID', ''),
            'montaClientSecret' => env('MONTA_CLIENT_SECRET', ''),
            'montaAuthEndpoint' => 'https://public-api.monta.com/api/v1/auth/token',
            'montaDataEndpoint' => 'https://public-api.monta.com/api/v1/charges',

            // Electricity cost — spot price area and grid operator GLN
            'elspotArea' => env('ELSPOT_AREA', 'DK2'),
            'elspotGln'  => env('ELSPOT_GLN', ''),

            // Weather data — GPS coordinates
            'weatherLat' => env('WEATHER_LAT', ''),
            'weatherLon' => env('WEATHER_LON', ''),

            // Cache directory
            'cacheDir' => rtrim(env('CACHE_DIR', sys_get_temp_dir()), '/\\'),

            // Admin key (system log access)
            'adminKey' => env('ADMIN_KEY', ''),

            // Vehicle telemetry API key
            'vehicleApiKey' => env('VEHICLE_API_KEY', ''),

            // Debug mode
            'debug' => env('DEBUG', 'false') === 'true',
        ];
    }

    /**
     * Get a configuration value by key.
     *
     * @param string $key     Configuration key name
     * @param mixed  $default Default value if key not found
     * @return mixed
     */
    public static function get(string $key, mixed $default = ''): mixed {
        self::ensureLoaded();
        return self::$values[$key] ?? $default;
    }

    // =========================================================================
    // Typed shortcuts for frequently used values
    // =========================================================================

    public static function dbPath(): string {
        return self::get('dbPath', 'data/charging_data.db');
    }

    public static function powerlogDbPath(): string {
        return self::get('powerlogDbPath', 'data/powerlog_data.db');
    }

    public static function cacheDir(): string {
        return self::get('cacheDir', sys_get_temp_dir());
    }

    public static function adminKey(): string {
        return self::get('adminKey', '');
    }

    public static function vehicleApiKey(): string {
        return self::get('vehicleApiKey', '');
    }

    public static function elspotArea(): string {
        return self::get('elspotArea', 'DK2');
    }

    public static function elspotGln(): string {
        return self::get('elspotGln', '');
    }

    public static function weatherLat(): string {
        return self::get('weatherLat', '');
    }

    public static function weatherLon(): string {
        return self::get('weatherLon', '');
    }

    public static function debug(): bool {
        return (bool) self::get('debug', false);
    }

    public static function montaClientId(): string {
        return self::get('montaClientId', '');
    }

    public static function montaClientSecret(): string {
        return self::get('montaClientSecret', '');
    }

    public static function montaAuthEndpoint(): string {
        return self::get('montaAuthEndpoint', 'https://public-api.monta.com/api/v1/auth/token');
    }

    public static function montaDataEndpoint(): string {
        return self::get('montaDataEndpoint', 'https://public-api.monta.com/api/v1/charges');
    }

    // =========================================================================
    // Testing support
    // =========================================================================

    /**
     * Override configuration values (for testing only).
     */
    public static function override(array $overrides): void {
        self::ensureLoaded();
        self::$values = array_merge(self::$values, $overrides);
    }

    /**
     * Reset configuration state (for testing only).
     */
    public static function reset(): void {
        self::$values = [];
        self::$loaded = false;
    }

    private static function ensureLoaded(): void {
        if (!self::$loaded) {
            self::load();
        }
    }
}
