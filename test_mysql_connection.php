<?php
/**
 * Test MySQL Heat Pump Connection
 *
 * This script tests if MySQLManager can connect to the MySQL server
 * and verifies the configuration is correct.
 *
 * Usage: php test_mysql_connection.php
 */

require 'includes/configuration.php';
require 'includes/MySQLManager.php';

echo "=== SparkSpend MySQL Heat Pump Connection Test ===\n\n";

// Check environment variables
echo "Checking environment configuration...\n";
$required_vars = ['MYSQL_HEATPUMP_HOST', 'MYSQL_HEATPUMP_USER', 'MYSQL_HEATPUMP_DATABASE'];
foreach ($required_vars as $var) {
    $value = env($var);
    if ($value) {
        echo "✓ $var configured\n";
    } else {
        echo "✗ $var missing - please add to .env file\n";
    }
}

echo "\nMySQL Configuration:\n";
echo "  Host:     " . (env('MYSQL_HEATPUMP_HOST') ?: 'NOT SET') . "\n";
echo "  Port:     " . (env('MYSQL_HEATPUMP_PORT', '3306')) . "\n";
echo "  User:     " . (env('MYSQL_HEATPUMP_USER') ?: 'NOT SET') . "\n";
echo "  Database: " . (env('MYSQL_HEATPUMP_DATABASE') ?: 'NOT SET') . "\n";
echo "  Table:    " . (env('MYSQL_HEATPUMP_TABLE', 'powerlog')) . "\n";

echo "\nAttempting MySQL connection...\n";

try {
    if (MySQLManager::testConnection()) {
        echo "✓ MySQL connection successful!\n\n";

        // Try to fetch a sample record
        $db = MySQLManager::getHeatpumpDb();
        $tableName = env('MYSQL_HEATPUMP_TABLE', 'powerlog');

        echo "Checking table structure...\n";
        $stmt = $db->query("DESCRIBE $tableName");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "Columns found:\n";
        foreach ($columns as $col) {
            echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
        }

        echo "\nSample records (last 5):\n";
        $stmt = $db->query("SELECT * FROM $tableName ORDER BY logdate DESC LIMIT 5");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            echo "  (no records found)\n";
        } else {
            foreach ($records as $record) {
                echo "  - " . json_encode($record) . "\n";
            }
        }

    } else {
        echo "✗ MySQL connection failed\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Test Complete ===\n";
?>
