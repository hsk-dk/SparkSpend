<?php
/**
 * Get heat pump power consumption data
 *
 * GET parameters:
 * - mode: 'daily' (with month), 'monthly' (with year), or 'compare'
 * - month: Month in format YYYY-MM (required for daily mode)
 * - year: Year in format YYYY (required for monthly mode)
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';

header('Content-Type: application/json');

try {
    $db = DatabaseManager::getPowerlogDb();

    $mode = $_GET['mode'] ?? 'daily';
    $response = [];

    if ($mode === 'daily' && isset($_GET['month'])) {
        // Daily consumption summary based on difference between first and last reading
        $month = $_GET['month']; // Format: YYYY-MM
        $stmt = $db->prepare("
            SELECT
                DATE(logdate) AS day,
                (MAX(kwh) - MIN(kwh)) AS total_kwh
            FROM powerlogjord
            WHERE strftime('%Y-%m', logdate) = ?
            GROUP BY day
            ORDER BY day
        ");
        $stmt->execute([$month]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    } elseif ($mode === 'monthly' && isset($_GET['year'])) {
        // Monthly consumption summary based on difference between first and last reading
        $year = $_GET['year']; // Format: YYYY
        $stmt = $db->prepare("
            SELECT
                strftime('%Y-%m', logdate) AS month,
                (MAX(kwh) - MIN(kwh)) AS total_kwh
            FROM powerlogjord
            WHERE strftime('%Y', logdate) = ?
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute([$year]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    } elseif ($mode === 'compare') {
        // Compare monthly consumption across years
        $stmt = $db->prepare("
            SELECT
                strftime('%Y', logdate) AS year,
                strftime('%m', logdate) AS month,
                (MAX(kwh) - MIN(kwh)) AS total_kwh
            FROM powerlogjord
            GROUP BY year, month
            ORDER BY year DESC, month
        ");
        $stmt->execute([]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    } elseif ($mode === 'sync_status') {
        // Return last sync timestamp from sync_log
        $stmt = $db->prepare("SELECT last_sync_timestamp, updated_at FROM sync_log WHERE source = 'powerlogjord' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $response = $row ?: ['last_sync_timestamp' => null, 'updated_at' => null];
        http_response_code(200);
        echo json_encode($response);
        exit;
    }

    // Return empty array if no data found
    if (empty($response)) {
        http_response_code(200);
        echo json_encode([]);
        exit;
    }

    http_response_code(200);
    echo json_encode($response);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in getHeatpumpData.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in getHeatpumpData.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>

