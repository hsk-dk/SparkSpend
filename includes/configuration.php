<?php
/**
 * SparkSpend Application Configuration
 *
 * Loads configuration from environment variables (.env file)
 * All sensitive values should be stored in .env, not in source code
 */

// Load environment variables from .env file
require_once 'env.php';

// Monta API Credentials - loaded from .env
$clientId = env('MONTA_CLIENT_ID');
$clientSecret = env('MONTA_CLIENT_SECRET');

// Validate that credentials are configured
if (empty($clientId) || empty($clientSecret)) {
    die('Error: MONTA_CLIENT_ID and MONTA_CLIENT_SECRET must be configured in .env file');
}

// Monta API Endpoints (static, no changes needed)
$authEndpoint = 'https://public-api.monta.com/api/v1/auth/token';
$dataEndpoint = 'https://public-api.monta.com/api/v1/charges';

// Database Paths - loaded from .env
$GLOBALS['dbPath'] = env('CHARGING_DB_PATH', 'data/charging_data.db');
$GLOBALS['powerlogDbPath'] = env('POWERLOG_DB_PATH', 'data/powerlog_data.db');

// MySQL Heat Pump Configuration - loaded from .env
$GLOBALS['mysqlHeatpumpHost'] = env('MYSQL_HEATPUMP_HOST');
$GLOBALS['mysqlHeatpumpPort'] = env('MYSQL_HEATPUMP_PORT', '3306');
$GLOBALS['mysqlHeatpumpUser'] = env('MYSQL_HEATPUMP_USER');
$GLOBALS['mysqlHeatpumpPassword'] = env('MYSQL_HEATPUMP_PASSWORD', '');
$GLOBALS['mysqlHeatpumpDatabase'] = env('MYSQL_HEATPUMP_DATABASE');
$GLOBALS['mysqlHeatpumpTable'] = env('MYSQL_HEATPUMP_TABLE', 'powerlog');
$GLOBALS['mysqlHousePowerTable'] = env('MYSQL_HOUSEPOWERLOG_TABLE', 'powerloghus');
$GLOBALS['heatpumpSyncInterval'] = env('HEATPUMP_SYNC_INTERVAL', '300'); // Default: 5 minutes

// Electricity cost settings — spot price area and grid operator GLN
$GLOBALS['elspotArea'] = env('ELSPOT_AREA', 'DK2');
$GLOBALS['elspotGln']  = env('ELSPOT_GLN',  '');

// Weather data — GPS coordinates for degree-day (HDD) calculation via Open-Meteo
$GLOBALS['weatherLat'] = env('WEATHER_LAT', '');
$GLOBALS['weatherLon'] = env('WEATHER_LON', '');

// Timezone Configuration
$timezone = env('TIMEZONE', 'Europe/Copenhagen');
date_default_timezone_set($timezone);

// Debug Mode
$debugMode = env('DEBUG', 'false') === 'true';

// Error Reporting Configuration
error_reporting(E_ALL);
ini_set('display_errors', 0); // Never display errors to users
ini_set('log_errors', 1);

// Error Logging Path
$logPath = env('LOG_PATH', '/var/log/php_errors.log');
ini_set('error_log', $logPath);
?>