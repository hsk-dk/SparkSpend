<?php
/**
 * Get heat pump power consumption data
 *
 * All modes use a LAG-based daily-delta approach (MAX(kwh) per day minus the
 * prior day's MAX) instead of MAX-MIN within each period.  This prevents
 * inflated readings when the cumulative kWh meter resets mid-period.
 *
 * GET parameters:
 * - mode: 'daily' (with month), 'monthly' (with year), 'compare', or 'ytd'
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
        $month = $_GET['month']; // Format: YYYY-MM
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid month format. Use YYYY-MM.']);
            exit;
        }
        // Inter-day LAG deltas: resistant to mid-day meter resets.
        // Extend window one day back so the first day of the month gets a
        // valid prior-day baseline.
        $stmt = $db->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)  AS day,
                       MAX(kwh)       AS max_kwh
                FROM powerlogjord
                WHERE DATE(logdate) >= DATE(?||'-01', '-1 day')
                  AND DATE(logdate) <= DATE(?||'-01', '+1 month', '-1 day')
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS total_kwh
                FROM daily_max
            )
            SELECT day,
                   ROUND(total_kwh, 2) AS total_kwh
            FROM daily_delta
            WHERE strftime('%Y-%m', day) = ?
            ORDER BY day
        ");
        $stmt->execute([$month, $month, $month]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    } elseif ($mode === 'monthly' && isset($_GET['year'])) {
        $year = $_GET['year']; // Format: YYYY
        if (!preg_match('/^\d{4}$/', $year)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid year format. Use YYYY.']);
            exit;
        }
        // Same LAG-delta strategy as daily mode, now aggregated to monthly.
        // Extend window one day before Jan 1 so January gets a valid prior-day baseline.
        $stmt = $db->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)  AS day,
                       MAX(kwh)       AS max_kwh
                FROM powerlogjord
                WHERE DATE(logdate) >= DATE(?||'-01-01', '-1 day')
                  AND DATE(logdate) <= ?||'-12-31'
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       strftime('%Y-%m', day)                              AS month,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
                FROM daily_max
            )
            SELECT month,
                   ROUND(SUM(delta_kwh), 2) AS total_kwh
            FROM daily_delta
            WHERE strftime('%Y', day) = ?
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute([$year, $year, $year]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    } elseif ($mode === 'compare') {
        // Compare monthly consumption across years.
        // Only include months where recorded days cover >= 90% of the month,
        // to avoid partial months skewing the comparison.
        // LAG-delta across all data, then group by year+month with the
        // existing 90% day-coverage HAVING filter.  The very first day in the
        // dataset gets a NULL LAG → MAX(NULL, 0) = 0, which is acceptable.
        $stmt = $db->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)  AS day,
                       MAX(kwh)       AS max_kwh
                FROM powerlogjord
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       strftime('%Y', day)                                  AS year,
                       strftime('%m', day)                                  AS month,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0)  AS delta_kwh
                FROM daily_max
            )
            SELECT year, month,
                   ROUND(SUM(delta_kwh), 2) AS total_kwh
            FROM daily_delta
            GROUP BY year, month
            HAVING COUNT(DISTINCT day) >=
                CAST(strftime('%d', date(
                    year || '-' || month || '-01',
                    '+1 month', '-1 day'
                )) AS INTEGER) * 0.9
            ORDER BY year DESC, month
        ");
        $stmt->execute([]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    } elseif ($mode === 'ytd') {
        // Daily consumption per year, Jan 1 through today's day-of-year
        $todayMd = date('m-d');
        // PARTITION BY year so each year is an independent series.
        // Jan 1 of each year gets NULL LAG → clamped to 0 (unavoidable without
        // cross-year data, and Jan 1 consumption is a minor edge case).
        $stmt = $db->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)           AS day,
                       strftime('%Y', logdate) AS year,
                       MAX(kwh)                AS max_kwh
                FROM powerlogjord
                WHERE strftime('%m-%d', logdate) <= ?
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day, year,
                       MAX(max_kwh - LAG(max_kwh) OVER (PARTITION BY year ORDER BY day), 0) AS daily_kwh
                FROM daily_max
            )
            SELECT year, day,
                   ROUND(daily_kwh, 2) AS daily_kwh
            FROM daily_delta
            ORDER BY year, day
        ");
        $stmt->execute([$todayMd]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    } elseif ($mode === 'sync_status') {
        // Return last sync timestamp from sync_log
        $stmt = $db->prepare("SELECT last_sync_timestamp, updated_at FROM sync_log WHERE source = 'heatpump' LIMIT 1");
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

