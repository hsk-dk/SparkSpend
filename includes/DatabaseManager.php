<?php
/**
 * Database Connection Manager for SparkSpend
 *
 * Provides centralized PDO connection management for both databases:
 * - charging_data.db (vehicle charges, vehicles, providers)
 * - powerlog_data.db (heat pump power consumption logs)
 *
 * Uses a singleton pattern to avoid multiple connections to the same database
 */

class DatabaseManager {
    private static $chargesDb = null;
    private static $powerlogDb = null;

    /**
     * Get PDO connection to charging database
     *
     * @return PDO The database connection
     * @throws Exception If connection fails
     */
    public static function getChargesDb(): PDO {
        if (self::$chargesDb === null) {
            self::$chargesDb = self::connect(Config::dbPath(), 'charges');
        }
        return self::$chargesDb;
    }

    /**
     * Get PDO connection to powerlog database
     *
     * @return PDO The database connection
     * @throws Exception If connection fails
     */
    public static function getPowerlogDb(): PDO {
        if (self::$powerlogDb === null) {
            self::$powerlogDb = self::connect(Config::powerlogDbPath(), 'powerlog');
        }
        return self::$powerlogDb;
    }

    /**
     * Reset connections (for testing purposes)
     */
    public static function reset(): void {
        self::$chargesDb = null;
        self::$powerlogDb = null;
    }

    /**
     * Create and configure a PDO connection
     *
     * @param string $dbPath Path to SQLite database file
     * @return PDO Configured PDO instance
     * @throws Exception If database file doesn't exist or is not readable
     */
    private static function connect(string $dbPath, string $dbType = 'charges'): PDO {
        // Validate database file
        if (!self::validateDatabaseFile($dbPath)) {
            throw new Exception("Database file not found or not readable: {$dbPath}");
        }

        try {
            // Create PDO connection to SQLite database
            $db = new PDO('sqlite:' . $dbPath);

            // Configure PDO error mode to throw exceptions
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Set default fetch mode to associative array
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Set connection timeout
            $db->setAttribute(PDO::ATTR_TIMEOUT, 30);

            // Enable WAL mode for better concurrent read/write performance.
            // WAL allows readers and writers to operate simultaneously without blocking.
            $db->exec("PRAGMA journal_mode=WAL");
            $db->exec("PRAGMA busy_timeout=5000");

            // Run database migrations automatically (checked at most once per
            // hour to avoid overhead). Can be disabled with AUTO_MIGRATE=false so
            // production relies on the explicit `php migrate.php` deploy step
            // instead of migrating on a web request.
            if (Config::autoMigrate()) {
                require_once dirname(__FILE__) . '/MigrationRunner.php';
                $migrationsDir = dirname(dirname(__FILE__)) . '/migrations/' . $dbType;
                MigrationRunner::runIfDue($db, $migrationsDir, 3600);
            }

            return $db;
        } catch (PDOException $e) {
            throw new Exception("Failed to connect to database {$dbPath}: " . $e->getMessage());
        }
    }

    /**
     * Validate that database file exists and is readable
     *
     * @param string $dbPath Path to check
     * @return bool True if file is valid, false otherwise
     */
    private static function validateDatabaseFile(string $dbPath): bool {
        // Convert relative paths to absolute
        if (!str_starts_with($dbPath, '/') && !preg_match('/^[a-z]:/i', $dbPath)) {
            $dbPath = dirname(dirname(__FILE__)) . '/' . $dbPath;
        }

        // Check if file exists
        if (!file_exists($dbPath)) {
            return false;
        }

        // Check if file is readable
        if (!is_readable($dbPath)) {
            return false;
        }

        // Note: We don't validate the SQLite header format here because:
        // 1. PDO will validate it properly when connecting
        // 2. Different SQLite versions may have slightly different headers
        // 3. The file_exists and is_readable checks are sufficient for basic validation

        return true;
    }
}
?>
