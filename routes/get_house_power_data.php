<?php
/**
 * House Power Consumption API
 *
 * Returns daily or monthly house electricity consumption from powerloghus,
 * combined with heat pump (powerlogjord) and EV charging breakdowns.
 *
 * GET parameters:
 *   mode   — 'daily', 'monthly', 'compare', 'ytd', or 'sync_status'
 *   month  — YYYY-MM  (required for daily mode)
 *   year   — YYYY     (required for monthly mode)
 */

// Helper: accumulate EV external charges by date key.
function _extEvByKey(PDO $chargesDb, string $from, string $to, string $keyFmt): array {
    $rows = QueryBuilder::getExternalCharges($chargesDb, $from, $to);
    $result = [];
    foreach ($rows as $row) {
        $t = strtotime($row['datetime']);
        if (!$t) continue;
        $key = date($keyFmt, $t);
        $result[$key] = ($result[$key] ?? 0.0) + floatval($row['kwh']);
    }
    return $result;
}

// ─── 1-hour file cache (skip sync_status mode — changes frequently) ───────────────
$_hwCacheKey = 'housepower_' . md5($_SERVER['QUERY_STRING'] ?? '');
if (($_GET['mode'] ?? '') !== 'sync_status') {
    $_cached = QueryBuilder::fileCacheRead($_hwCacheKey, 3600);
    if ($_cached !== null) { echo $_cached; exit; }
}
// ─────────────────────────────────────────────────────────────────────────────

$powerlogDb = DatabaseManager::getPowerlogDb();
$chargesDb  = DatabaseManager::getChargesDb();

$mode = $_GET['mode'] ?? 'daily';

// =========================================================================
// DAILY — per-day breakdown for a single month
// =========================================================================
if ($mode === 'daily') {
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid month format. Use YYYY-MM.']);
        exit;
    }
    $dayStart = $month . '-01';
    $dayEnd   = date('Y-m-t', strtotime($dayStart));

    // House meter
    $husByDay = [];
    try {
        $husByDay = QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $dayStart, $dayEnd);
    } catch (\Throwable $e) {
        error_log('getHousePowerData house daily: ' . $e->getMessage());
    }

    // HP meter
    $hpByDay = QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $dayStart, $dayEnd);

    // EV internal — proportional midnight-split
    $evByDay = [];
    $stmt = $chargesDb->prepare("
        SELECT startedAt, stoppedAt, COALESCE(consumedKwh, 0) AS kwh
        FROM charges
        WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
    ");
    $stmt->execute([$dayEnd, $dayStart]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $splits = QueryBuilder::splitChargeByDays(
            $row['startedAt'], $row['stoppedAt'], floatval($row['kwh']), 0.0
        );
        foreach ($splits as $day => $slice) {
            if ($day < $dayStart || $day > $dayEnd) continue;
            $evByDay[$day] = ($evByDay[$day] ?? 0.0) + $slice['kwh'];
        }
    }

    // EV external
    foreach (_extEvByKey($chargesDb, $dayStart, $dayEnd, 'Y-m-d') as $day => $kwh) {
        $evByDay[$day] = ($evByDay[$day] ?? 0.0) + $kwh;
    }

    // Merge into ordered day list
    $allDays = array_unique(array_merge(
        array_keys($husByDay), array_keys($hpByDay), array_keys($evByDay)
    ));
    sort($allDays);

    $days = [];
    foreach ($allDays as $day) {
        $hus  = $husByDay[$day] ?? 0.0;
        $ev   = round($evByDay[$day] ?? 0.0, 2);
        $hp   = round($hpByDay[$day] ?? 0.0, 2);
        $rest = round(max(0, $hus - $ev - $hp), 2);
        $days[] = ['day' => $day, 'hus' => round($hus, 2), 'ev' => $ev, 'hp' => $hp, 'rest' => $rest];
    }

    $json = json_encode(['mode' => 'daily', 'days' => $days]);
    QueryBuilder::fileCacheWrite($_hwCacheKey, $json);
    echo $json;

// =========================================================================
// MONTHLY — per-month breakdown for a single year
// =========================================================================
} elseif ($mode === 'monthly') {
    $year = $_GET['year'] ?? date('Y');
    if (!preg_match('/^\d{4}$/', $year)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid year format. Use YYYY.']);
        exit;
    }
    $yearStart = $year . '-01-01';
    $yearEnd   = $year . '-12-31';

    // House meter
    $husByMonth = [];
    try {
        $husByMonth = QueryBuilder::lagDeltaByMonth($powerlogDb, 'powerloghus', $yearStart, $yearEnd);
    } catch (\Throwable $e) {
        error_log('getHousePowerData house monthly: ' . $e->getMessage());
    }

    // HP meter
    $hpByMonth = QueryBuilder::lagDeltaByMonth($powerlogDb, 'powerlogjord', $yearStart, $yearEnd);

    // EV internal monthly — proportional midnight-split
    $evByMonth = [];
    $stmt = $chargesDb->prepare("
        SELECT startedAt, stoppedAt, COALESCE(consumedKwh, 0) AS kwh
        FROM charges
        WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
    ");
    $stmt->execute([$yearEnd, $yearStart]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $splits = QueryBuilder::splitChargeByDays(
            $row['startedAt'], $row['stoppedAt'], floatval($row['kwh']), 0.0
        );
        foreach ($splits as $day => $slice) {
            if ($day < $yearStart || $day > $yearEnd) continue;
            $month = substr($day, 0, 7);
            $evByMonth[$month] = ($evByMonth[$month] ?? 0.0) + $slice['kwh'];
        }
    }

    // EV external monthly
    foreach (_extEvByKey($chargesDb, $yearStart, $yearEnd, 'Y-m') as $month => $kwh) {
        $evByMonth[$month] = ($evByMonth[$month] ?? 0.0) + $kwh;
    }

    // Emit all 12 months (skip if no data at all)
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $key = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
        $hus  = $husByMonth[$key] ?? 0.0;
        $ev   = round($evByMonth[$key] ?? 0.0, 2);
        $hp   = round($hpByMonth[$key] ?? 0.0, 2);
        $rest = round(max(0, $hus - $ev - $hp), 2);
        if ($hus > 0 || $ev > 0 || $hp > 0) {
            $months[] = ['month' => $key, 'hus' => round($hus, 2), 'ev' => $ev, 'hp' => $hp, 'rest' => $rest];
        }
    }

    $json = json_encode(['mode' => 'monthly', 'months' => $months]);
    QueryBuilder::fileCacheWrite($_hwCacheKey, $json);
    echo $json;

// =========================================================================
// COMPARE — house meter monthly totals across all years
// =========================================================================
} elseif ($mode === 'compare') {
    $data = [];
    try {
        $stmt = $powerlogDb->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate) AS day, MAX(kwh) AS max_kwh
                FROM powerloghus
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       strftime('%Y', day) AS year,
                       strftime('%m', day) AS month,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
                FROM daily_max
            )
            SELECT year, month, ROUND(SUM(delta_kwh), 2) AS kwh
            FROM daily_delta
            GROUP BY year, month
            HAVING COUNT(DISTINCT day) >=
                CAST(strftime('%d', date(
                    year || '-' || month || '-01', '+1 month', '-1 day'
                )) AS INTEGER) * 0.9
            ORDER BY year DESC, month
        ");
        $stmt->execute([]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('getHousePowerData compare: ' . $e->getMessage());
    }
    $json = json_encode(['mode' => 'compare', 'data' => $data]);
    QueryBuilder::fileCacheWrite($_hwCacheKey, $json);
    echo $json;

// =========================================================================
// YTD — house meter daily totals per year, Jan 1 through today's day-of-year
// =========================================================================
} elseif ($mode === 'ytd') {
    $todayMd = date('m-d');
    $data    = [];
    try {
        $stmt = $powerlogDb->prepare("
            WITH daily_max AS (
                SELECT DATE(logdate)           AS day,
                       strftime('%Y', logdate) AS year,
                       MAX(kwh)                AS max_kwh
                FROM powerloghus
                WHERE strftime('%m-%d', logdate) <= ?
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day, year,
                       MAX(max_kwh - LAG(max_kwh) OVER (PARTITION BY year ORDER BY day), 0) AS kwh
                FROM daily_max
            )
            SELECT year, day, ROUND(kwh, 2) AS kwh
            FROM daily_delta
            ORDER BY year, day
        ");
        $stmt->execute([$todayMd]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('getHousePowerData ytd: ' . $e->getMessage());
    }
    $json = json_encode(['mode' => 'ytd', 'data' => $data]);
    QueryBuilder::fileCacheWrite($_hwCacheKey, $json);
    echo $json;

// =========================================================================
// SYNC STATUS
// =========================================================================
} elseif ($mode === 'sync_status') {
    $stmt = $powerlogDb->prepare(
        "SELECT last_sync_timestamp, updated_at FROM sync_log WHERE source = 'housepowerlog' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($row ?: ['last_sync_timestamp' => null, 'updated_at' => null]);

} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown mode. Use daily, monthly, compare, ytd, or sync_status.']);
}
