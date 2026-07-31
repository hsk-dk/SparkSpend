<?php
/**
 * Get heat pump power consumption data
 *
 * All modes use a LAG-based daily-delta approach.
 *
 * GET parameters:
 * - mode: 'daily' (with month), 'monthly' (with year), 'compare', or 'ytd'
 * - month: Month in format YYYY-MM (required for daily mode)
 * - year: Year in format YYYY (required for monthly mode)
 */

// ─── 1-hour file cache (skip sync_status mode — changes frequently) ───────────────
$_hpCacheKey = 'heatpump_' . md5($_SERVER['QUERY_STRING'] ?? '');
if (($_GET['mode'] ?? '') !== 'sync_status') {
    $_cached = QueryBuilder::fileCacheRead($_hpCacheKey, 3600);
    if ($_cached !== null) { echo $_cached; exit; }
}
// ─────────────────────────────────────────────────────────────────────────────

$db = DatabaseManager::getPowerlogDb();

$mode = $_GET['mode'] ?? 'daily';
$response = [];

if ($mode === 'daily' && isset($_GET['month'])) {
    $month = $_GET['month']; // Format: YYYY-MM
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid month format. Use YYYY-MM.']);
        exit;
    }
    $dayStart = $month . '-01';
    $dayEnd   = date('Y-m-t', strtotime($dayStart));
    $daily    = QueryBuilder::lagDelta($db, 'powerlogjord', $dayStart, $dayEnd);
    $response = [];
    foreach ($daily as $day => $kwh) {
        $response[] = ['day' => $day, 'total_kwh' => $kwh];
    }

} elseif ($mode === 'monthly' && isset($_GET['year'])) {
    $year = $_GET['year']; // Format: YYYY
    if (!preg_match('/^\d{4}$/', $year)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid year format. Use YYYY.']);
        exit;
    }
    $yearStart = $year . '-01-01';
    $yearEnd   = $year . '-12-31';
    $monthly   = QueryBuilder::lagDeltaByMonth($db, 'powerlogjord', $yearStart, $yearEnd);
    $response  = [];
    foreach ($monthly as $month => $kwh) {
        $response[] = ['month' => $month, 'total_kwh' => $kwh];
    }

} elseif ($mode === 'compare') {
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
    $todayMd = date('m-d');
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
    $json = json_encode([]);
    QueryBuilder::fileCacheWrite($_hpCacheKey, $json);
    echo $json;
    exit;
}

http_response_code(200);
$json = json_encode($response);
QueryBuilder::fileCacheWrite($_hpCacheKey, $json);
echo $json;
