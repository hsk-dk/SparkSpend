<?php
// Load environment variables
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception('.env file not found. Please copy .env.example to .env and configure your settings.');
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
    }
}

// Load environment variables
loadEnv(__DIR__ . '/../.env');

// Monta API detaljer
$clientId = $_ENV['MONTA_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['MONTA_CLIENT_SECRET'] ?? '';

if (empty($clientId) || empty($clientSecret)) {
    throw new Exception('Missing Monta API credentials. Please configure .env file.');
}

//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
//!!!! There should be no reason to change anything beyond this line. !!!
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
$authEndpoint = 'https://public-api.monta.com/api/v1/auth/token';
$dataEndpoint = 'https://public-api.monta.com/api/v1/charges';

// Sti til SQLite-database
$dbPath = 'data/charging_data.db';
?>