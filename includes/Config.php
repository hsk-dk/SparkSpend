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

            // Auto-run DB migrations on web requests. Default true (preserves
            // existing behaviour). Set AUTO_MIGRATE=false in production to run
            // migrations only via the explicit `php migrate.php` deploy step,
            // avoiding schema changes on a random end-user request.
            'autoMigrate' => env('AUTO_MIGRATE', 'true') !== 'false',

            // ── Electricity tax / tariff constants ───────────────────────────
            // VAT multiplier (moms). Overridable via env for future rate changes.
            'vatFactor'    => (float) env('VAT_FACTOR', '1.25'),
            // Energinet's GLN (systemtarif lookup) — stable, but centralised here.
            'energinetGln' => env('ENERGINET_GLN', '5790000432752'),
            // Legacy Elspotprices → DayAheadPrices dataset cutover date.
            'elspotCutoff' => env('ELSPOT_CUTOFF', '2025-09-30'),
            // Electricity tax (elafgift), kr/kWh, versioned by effective-from
            // date. The applicable rate is the newest whose date <= the queried
            // date, so historical calculations use the correct rate. Source:
            // skat.dk — edit these tables when a new annual rate is published.
            //
            // Danish electricity-heated homes: the meter can't tell purpose
            // apart, so the FULL ("high") rate applies to the first 4000 kWh/yr
            // and the reduced ELVARME ("low") rate applies to everything above.
            //
            // HIGH = almindelig elafgift (2025 ≈ 0.761 kr/kWh).
            'elafgiftHighByDate' => [
                '2024-01-01' => 0.761,
                '2025-01-01' => 0.761,
                '2026-01-01' => 0.008,
                '2027-01-01' => 0.008,
            ],
            // LOW = elvarmeafgift (reduced rate, ≈ 0.8 øre/kWh since 2021).
            'elafgiftLowByDate' => [
                '2024-01-01' => 0.008,
                '2025-01-01' => 0.008,
                '2026-01-01' => 0.008,
                '2027-01-01' => 0.008,
            ],
            // Annual kWh taxed at the HIGH rate before the elvarme (LOW) rate
            // kicks in. Applied to VP + Rest (not EV); 4000/12 ≈ 333 kWh/month.
            'elvarmeHighKwhPerYear' => (float) env('ELVARME_HIGH_KWH_YEAR', '4000'),

            // ── Vehicle↔charge pairing (Monta sync) ──────────────────────────
            // The cable is plugged into the car first (HA sends vehicleId +
            // odometer at that moment), then Monta may schedule the charge to
            // start hours later. So a charge belongs to the vehicle whose cable
            // was plugged in most recently AT OR BEFORE the charge's
            // cablePluggedInAt. We pick the LATEST HA reading with ts <= T,
            // allowing a small grace window after T for clock/latency skew.
            'pairingGraceAfterSec'   => (int) env('PAIRING_GRACE_AFTER_SEC', '300'),      // 5 min after T
            // Don't attribute a charge to a plug-in older than this (a stale
            // reading from days ago is unreliable) → flag as uncertain instead.
            'pairingMaxLookbackSec'  => (int) env('PAIRING_MAX_LOOKBACK_SEC', '172800'),  // 48 h
            // If a DIFFERENT vehicle also plugged in within this window just
            // before the chosen reading, the pairing is ambiguous → uncertain.
            'pairingAmbiguitySec'    => (int) env('PAIRING_AMBIGUITY_SEC', '600'),        // 10 min
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

    public static function autoMigrate(): bool {
        return (bool) self::get('autoMigrate', true);
    }

    // =========================================================================
    // Electricity tax / tariff helpers
    // =========================================================================

    public static function vatFactor(): float {
        return (float) self::get('vatFactor', 1.25);
    }

    public static function energinetGln(): string {
        return self::get('energinetGln', '5790000432752');
    }

    public static function elspotCutoff(): string {
        return self::get('elspotCutoff', '2025-09-30');
    }

    /**
     * Look up the newest rate in a date-keyed table whose effective-from date
     * is <= $date (falls back to the earliest known rate for older dates).
     */
    private static function rateForDate(array $table, string $date, float $fallback): float {
        if (empty($table)) return $fallback;
        ksort($table);
        $rate = reset($table);
        foreach ($table as $from => $value) {
            if ($from <= $date) {
                $rate = $value;
            } else {
                break;
            }
        }
        return (float) $rate;
    }

    /**
     * HIGH electricity tax (almindelig elafgift), kr/kWh, on the given date.
     * Applies to the first 4000 kWh/year of VP + Rest consumption.
     */
    public static function elafgiftHigh(string $date): float {
        self::ensureLoaded();
        return self::rateForDate(self::$values['elafgiftHighByDate'] ?? [], $date, 0.761);
    }

    /**
     * LOW electricity tax (elvarmeafgift), kr/kWh, on the given date. Applies to
     * VP + Rest above the annual high-rate threshold, and to all EV charging.
     */
    public static function elafgiftLow(string $date): float {
        self::ensureLoaded();
        return self::rateForDate(self::$values['elafgiftLowByDate'] ?? [], $date, 0.008);
    }

    /** kWh/month taxed at the HIGH rate before the elvarme rate applies. */
    public static function elvarmeHighKwhPerMonth(): float {
        self::ensureLoaded();
        return (float) self::get('elvarmeHighKwhPerYear', 4000.0) / 12.0;
    }

    // =========================================================================
    // Vehicle↔charge pairing thresholds (seconds)
    // =========================================================================

    public static function pairingGraceAfterSec(): int {
        return (int) self::get('pairingGraceAfterSec', 300);
    }

    public static function pairingMaxLookbackSec(): int {
        return (int) self::get('pairingMaxLookbackSec', 172800);
    }

    public static function pairingAmbiguitySec(): int {
        return (int) self::get('pairingAmbiguitySec', 600);
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
