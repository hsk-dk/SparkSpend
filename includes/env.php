<?php
/**
 * Environment variable loader for SparkSpend
 *
 * Loads configuration from .env file in root directory
 * Provides getEnv() helper function to access environment variables
 */

class EnvLoader {
    private static $variables = null;

    /**
     * Load environment variables from .env file
     */
    public static function load(): void {
        if (self::$variables !== null) {
            return; // Already loaded
        }

        self::$variables = [];
        $envFile = dirname(dirname(__FILE__)) . '/.env';

        if (!file_exists($envFile)) {
            return; // .env file not found, use defaults
        }

        if (!is_readable($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Check if file() succeeded
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            // Remove any carriage returns and whitespace (handles both Unix and Windows line endings)
            $line = trim($line, " \t\r\n");

            // Skip empty lines and comments
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Parse KEY=VALUE format
            if (strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            // Trim whitespace and special characters
            $key = trim($key, " \t\r\n");
            $value = trim($value, " \t\r\n");

            // Skip empty keys or values
            if (empty($key) || empty($value)) {
                continue;
            }

            // Remove quotes if present
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }

            self::$variables[$key] = $value;
        }
    }

    /**
     * Get environment variable value
     *
     * @param string $key The variable name
     * @param string|null $default Default value if not found
     * @return string|null The variable value or default
     */
    public static function get(string $key, ?string $default = null): ?string {
        self::load();

        // Check .env file first
        if (isset(self::$variables[$key])) {
            return self::$variables[$key];
        }

        // Check PHP environment variables
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        // Return default
        return $default;
    }
}

// Load environment variables on include
EnvLoader::load();

/**
 * Helper function to get environment variable
 * Usage: $value = env('VARIABLE_NAME', 'default_value')
 *
 * @param string $key The variable name
 * @param string|null $default Default value if not found
 * @return string|null The variable value or default
 */
if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string {
        return EnvLoader::get($key, $default);
    }
}
?>

