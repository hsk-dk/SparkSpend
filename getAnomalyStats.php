<?php
/**
 * Anomaly Detection API
 *
 * Compares the current-month-to-date daily average kWh against the same
 * calendar-month period last year for three categories:
 *   1. Hus (whole-house meter, powerloghus)
 *   2. Jordvarme (heat pump, powerlogjord)
 *   3. El-bil (internal Monta + external charges)
 *
 * An anomaly is raised when the current daily average exceeds the baseline
 * daily average by:
 *   info     ≥ 10%  (returned but does not trigger badge/panel)
 *   warning  ≥ 30%
 *   critical ≥ 60%
 *
 * Requires ≥ 5 days in the current month before reporting anything, to avoid
 * false positives at the start of the month.
 *
 * Response:
 *   anomalies[]      — ordered by severity desc (critical → warning → info),
 *                      then by category priority (hus → hp → ev)
 *     category       : "hus" | "hp" | "ev"
 *     severity       : "critical" | "warning" | "info"
 *     title          : Danish display name
 *     message        : human-readable explanation
 *     pct_above      : integer % above baseline (positive = above)
 *     current_avg    : float current-MTD daily average kWh
 *     baseline_avg   : float last-year same-period daily average kWh
 *     nav_section    : main tab ID to navigate to
 *     nav_sub        : sub-tab ID (or null)
 *   insufficient_data : bool — true when < 5 days into the month
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

// ─── 5-minute file cache (keyed by date) ──────────────────────────────────────
$_cacheKey = 'anomaly_' . date('Y-m-d');
$_cached   = QueryBuilder::fileCacheRead($_cacheKey, 300);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

try {
    $today      = new DateTimeImmutable('today');
    $dayOfMonth = (int)$today->format('j');

    // Require at least 5 days before raising alerts
    if ($dayOfMonth < 5) {
        $json = json_encode(['anomalies' => [], 'insufficient_data' => true]);
        QueryBuilder::fileCacheWrite($_cacheKey, $json);
        echo $json;
        exit;
    }

    $chargesDb  = DatabaseManager::getChargesDb();
    $powerlogDb = DatabaseManager::getPowerlogDb();

    // Date ranges
    $curMonthStart = $today->modify('first day of this month')->format('Y-m-d');
    $curMonthEnd   = $today->format('Y-m-d');
    $lyToday       = $today->modify('-1 year');
    $lyMonthStart  = $lyToday->modify('first day of this month')->format('Y-m-d');
    $lyMonthEnd    = $lyToday->format('Y-m-d'); // same day-of-month last year → MTD comparison

    $curDays = $dayOfMonth;
    $lyDays  = $dayOfMonth; // same number of days in both periods

    // Severity thresholds
    $threshCritical = 60;
    $threshWarning  = 30;
    $threshInfo     = 10;

    $anomalies = [];

    // =========================================================================
    // Helper — build anomaly entry
    // =========================================================================
    $makeAnomaly = function (
        string $category,
        string $title,
        float $curKwh,
        float $lyKwh,
        string $navSection,
        ?string $navSub
    ) use ($curDays, $lyDays, $threshCritical, $threshWarning, $threshInfo): ?array {
        if ($lyKwh <= 0 || $curKwh <= 0) return null;

        $curAvg   = $curKwh / $curDays;
        $lyAvg    = $lyKwh  / $lyDays;
        $pctAbove = (int)round(($curAvg / $lyAvg - 1) * 100);

        if ($pctAbove < $threshInfo) return null;

        $severity = $pctAbove >= $threshCritical ? 'critical'
                  : ($pctAbove >= $threshWarning  ? 'warning' : 'info');

        return [
            'category'     => $category,
            'severity'     => $severity,
            'title'        => $title,
            'message'      => 'Dagligt gennemsnit '
                . number_format($curAvg, 1, ',', '.')
                . ' kWh — ' . $pctAbove . '% over samme periode sidste år ('
                . number_format($lyAvg, 1, ',', '.') . ' kWh/dag).',
            'pct_above'    => $pctAbove,
            'current_avg'  => round($curAvg, 2),
            'baseline_avg' => round($lyAvg, 2),
            'nav_section'  => $navSection,
            'nav_sub'      => $navSub,
        ];
    };

    // =========================================================================
    // 1. HUS — whole-house meter (highest priority)
    // =========================================================================
    try {
        $husCur = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $curMonthStart, $curMonthEnd));
        $husLy  = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerloghus', $lyMonthStart, $lyMonthEnd));
        $a = $makeAnomaly('hus', 'Husforbrug', $husCur, $husLy, 'hus-section', 'hus-forbrug');
        if ($a) $anomalies[] = $a;
    } catch (\Throwable $husEx) {
        error_log('getAnomalyStats hus: ' . $husEx->getMessage());
        // powerloghus may not exist yet — skip silently
    }

    // =========================================================================
    // 2. HP — heat pump (powerlogjord)
    // =========================================================================
    $hpCur = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $curMonthStart, $curMonthEnd));
    $hpLy  = array_sum(QueryBuilder::lagDelta($powerlogDb, 'powerlogjord', $lyMonthStart, $lyMonthEnd));
    $a = $makeAnomaly('hp', 'Jordvarme', $hpCur, $hpLy, 'jordvarme-section', null);
    if ($a) $anomalies[] = $a;

    // =========================================================================
    // 3. EV — internal Monta charges + external charges
    // =========================================================================
    $evCurKwh = 0.0;
    $evLyKwh  = 0.0;

    // Internal charges — use midnight-split to attribute kWh to correct day
    $stmt = $chargesDb->prepare("
        SELECT startedAt, stoppedAt, COALESCE(consumedKwh, 0) AS kwh
        FROM charges
        WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
    ");
    $stmt->execute([$curMonthEnd, $lyMonthStart]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $splits = QueryBuilder::splitChargeByDays(
            $row['startedAt'], $row['stoppedAt'],
            floatval($row['kwh']), 0.0
        );
        foreach ($splits as $day => $slice) {
            if ($day >= $curMonthStart && $day <= $curMonthEnd) $evCurKwh += $slice['kwh'];
            if ($day >= $lyMonthStart  && $day <= $lyMonthEnd)  $evLyKwh  += $slice['kwh'];
        }
    }

    // External charges — Z-suffix in datetime requires strtotime for month attribution
    $stmtExt = $chargesDb->prepare(
        "SELECT datetime, kwh FROM ext_charges WHERE datetime >= ?"
    );
    $stmtExt->execute([$lyMonthStart]);
    foreach ($stmtExt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $t   = strtotime($row['datetime']);
        $day = $t ? date('Y-m-d', $t) : substr($row['datetime'], 0, 10);
        if ($day >= $curMonthStart && $day <= $curMonthEnd) $evCurKwh += floatval($row['kwh']);
        if ($day >= $lyMonthStart  && $day <= $lyMonthEnd)  $evLyKwh  += floatval($row['kwh']);
    }

    $a = $makeAnomaly('ev', 'El-bil', $evCurKwh, $evLyKwh, 'elbil-section', 'elbil-ladninger');
    if ($a) $anomalies[] = $a;

    // =========================================================================
    // Sort: critical first, then warning, then info
    // (category priority already maintained by insertion order)
    // =========================================================================
    $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($anomalies, fn($a, $b) => $severityRank[$a['severity']] <=> $severityRank[$b['severity']]);

    $json = json_encode(['anomalies' => $anomalies, 'insufficient_data' => false]);
    QueryBuilder::fileCacheWrite($_cacheKey, $json);
    echo $json;

} catch (\Throwable $e) {
    http_response_code(500);
    error_log('getAnomalyStats error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
