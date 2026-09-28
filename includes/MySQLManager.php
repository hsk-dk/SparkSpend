<?php
/**
 * MySQL Connection Manager
 *
 * Provides singleton pattern for MySQL database connections.
 * Loads credentials via Config (which reads from .env) and manages PDO instances.
 */

// Load environment configuration
require_once __DIR__ . '/configuration.php';

class MySQLManager {
    private static $heatpumpDb = null;

    /**
     * Get singleton instance of heat pump MySQL connection
     *
     * @return PDO MySQL database connection
     * @throws Exception If connection fails or credentials missing
     */
    public static function getHeatpumpDb(): PDO {
        if (self::$heatpumpDb === null) {
            self::$heatpumpDb = self::connect();
        }
        return self::$heatpumpDb;
    }

    /**
     * Create and configure MySQL PDO connection
     *
     * @return PDO Configured MySQL connection
     * @throws Exception If connection fails
     */
    private static function connect(): PDO {
        // Load credentials via Config (single source of configuration truth)
        // rather than reading env() directly here.
        $host     = Config::get('mysqlHeatpumpHost');
        $port     = Config::get('mysqlHeatpumpPort', '3306');
        $user     = Config::get('mysqlHeatpumpUser');
        $password = Config::get('mysqlHeatpumpPassword', '');
        $database = Config::get('mysqlHeatpumpDatabase');

        // Validate required credentials
        if (!$host || !$user || !$database) {
            throw new Exception(
                "MySQL heat pump credentials not configured. " .
                "Set MYSQL_HEATPUMP_HOST, MYSQL_HEATPUMP_USER, and MYSQL_HEATPUMP_DATABASE in .env file"
            );
        }

        try {
            // Create DSN (Data Source Name)
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

            // Create PDO connection
            $db = new PDO(
                $dsn,
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 30,
                    PDO::ATTR_STRINGIFY_FETCHES => false  // Return numbers as numbers, not strings
                ]
            );

            return $db;

        } catch (PDOException $e) {
            throw new Exception(
                "Failed to connect to MySQL heat pump database: " . $e->getMessage()
            );
        }
    }

    /**
     * Test MySQL connection
     *
     * @return bool True if connection successful
     */
    public static function testConnection(): bool {
        try {
            $db = self::getHeatpumpDb();
            $result = $db->query("SELECT 1");
            return $result !== false;
        } catch (Exception $e) {
            error_log("MySQL connection test failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Close the database connection
     *
     * @return void
     */
    public static function closeConnection(): void {
        self::$heatpumpDb = null;
    }
}
?>
