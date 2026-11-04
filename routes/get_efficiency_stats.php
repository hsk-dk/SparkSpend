<?php
/**
 * Consumption per km for each vehicle.
 *
 * The calculation lives in includes/EfficiencyCalc.php (pure, unit-tested):
 * km from the odometer at plug-in, kWh from the charges that refilled it, summed over
 * a period before dividing. Nothing is thrown away any more (no "< 20 km" or
 * "must have a charge" filter) and every figure carries a confidence level.
 *
 * GET parameters:
 * - filter:    vehicle id, or 'all'
 * - dateRange: "YYYY-MM-DD til YYYY-MM-DD" — selects the drives that END in the range.
 *              The rolling 30/90/365-day figures ignore it (they are always "until now").
 *
 * Response:
 *   {
 *     "vehicles": [{ vehicleId, overall, rolling: {"30","90","365"}, monthly: [{month, ...}], dropped }],
 *     "series":   [{ vehicleId, timestamp, date, totalKm, totalKwh, totalKmPerKwh, totalKrPerKm }],  // cumulative, for the charts
 *     "unassigned": { charges, kwh }     // charges without a vehicle: cannot be counted here
 *   }
 *   metrics: intervals, km, kwh, cost, kwh_per_100km, km_per_kwh, kr_per_km, kr_estimated,
 *            confidence (lav|middel|høj), confidence_why
 */
require_once __DIR__ . '/../includes/EfficiencyCalc.php';

// ─── 5-minute file cache ─────────────────────────────────────────────────────────────
$_effCacheKey = 'efficiency_' . md5(($_GET['filter'] ?? '') . '|' . ($_GET['dateRange'] ?? ''));
ApiCache::serve($_effCacheKey, CacheHelper::TTL_MEDIUM);
// ─────────────────────────────────────────────────────────────────────────────

$db = DatabaseManager::getChargesDb();

$filter    = $_GET['filter'] ?? 'all';
$dateRange = $_GET['dateRange'] ?? '';
$vehicleFilter = ($filter !== 'all' && $filter !== '') ? intval($filter) : null;

// A non-empty but unparseable range is a clear filter intent: reject it (400) rather
// than silently returning everything, which would look like a valid but wrong result.
$dateFilters = null;
if ($dateRange !== '') {
    try {
        $dateFilters = QueryBuilder::parseDateRange($dateRange);
    } catch (\Throwable $e) {
        Http::fail('Ugyldigt dateRange-format. Forventet: "YYYY-MM-DD til YYYY-MM-DD".');
    }
}
$fromTs = $dateFilters ? strtotime($dateFilters['start'] . ' 00:00:00') : null;
$toTs   = $dateFilters ? strtotime($dateFilters['end']   . ' 23:59:59') : null;

// ─── Data. ALL history is read (not just the selected range): a drive that ends
// inside the range may start before it, and needs the reading before the range. ──────
$vehicleSql = $vehicleFilter !== null ? ' AND vehicleId = ?' : '';
$vehicleArg = $vehicleFilter !== null ? [$vehicleFilter] : [];

$stmt = $db->prepare("
    SELECT vehicleId, cablePluggedInAt, odometer FROM vehicle_charges
    WHERE vehicleId IS NOT NULL AND odometer IS NOT NULL $vehicleSql
    ORDER BY vehicleId, cablePluggedInAt
");
$stmt->execute($vehicleArg);
$readingsByVehicle = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ts = strtotime((string) $r['cablePluggedInAt']);
    if ($ts === false) continue;
    $readingsByVehicle[(int) $r['vehicleId']][] = ['ts' => $ts, 'odometer' => (float) $r['odometer']];
}

// Charges per vehicle, timed by when they ended. Ongoing sessions (no stoppedAt) are
// not counted until they are finished: their energy is still partial.
$stmt = $db->prepare("
    SELECT vehicleId, stoppedAt AS datetime, consumedKwh AS kwh, cost AS pris FROM charges
    WHERE vehicleId IS NOT NULL AND stoppedAt IS NOT NULL AND consumedKwh > 0 $vehicleSql
");
$stmt->execute($vehicleArg);
$chargesByVehicle = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ts = strtotime((string) $r['datetime']);
    if ($ts === false) continue;
    $chargesByVehicle[(int) $r['vehicleId']][] = ['ts' => $ts, 'kwh' => (float) $r['kwh'], 'cost' => $r['pris'] !== null ? (float) $r['pris'] : null];
}
foreach (QueryBuilder::getExternalCharges($db, null, null, $vehicleFilter, false) as $r) {
    $ts = strtotime((string) ($r['datetime'] ?? ''));
    if ($ts === false || empty($r['vehicleId']) || (float) $r['kwh'] <= 0) continue;
    $chargesByVehicle[(int) $r['vehicleId']][] = ['ts' => $ts, 'kwh' => (float) $r['kwh'], 'cost' => isset($r['pris']) ? (float) $r['pris'] : null];
}

// ─── Per vehicle ──────────────────────────────────────────────────────────────────────
$tz   = new DateTimeZone(date_default_timezone_get());
$now  = time();
$vehicles = [];
$series   = [];

ksort($readingsByVehicle);
foreach ($readingsByVehicle as $vehicleId => $readings) {
    $calc  = EfficiencyCalc::intervals($readings, $chargesByVehicle[$vehicleId] ?? []);
    $all   = $calc['intervals'];
    $range = $dateFilters ? EfficiencyCalc::endingBetween($all, $fromTs, $toTs) : $all;

    $monthly = [];
    foreach (EfficiencyCalc::byMonth($range, $tz) as $month => $metrics) {
        $monthly[] = ['month' => $month] + $metrics;
    }

    $vehicles[] = [
        'vehicleId' => $vehicleId,
        'overall'   => EfficiencyCalc::metrics($range),
        'rolling'   => [
            '30'  => EfficiencyCalc::metrics(EfficiencyCalc::rolling($all, $now, 30)),
            '90'  => EfficiencyCalc::metrics(EfficiencyCalc::rolling($all, $now, 90)),
            '365' => EfficiencyCalc::metrics(EfficiencyCalc::rolling($all, $now, 365)),
        ],
        'monthly'   => $monthly,
        'dropped'   => $calc['dropped'],     // intervals skipped because the odometer went backwards
    ];

    foreach (EfficiencyCalc::cumulative($range) as $p) {
        $series[] = ['vehicleId' => $vehicleId, 'date' => date('Y-m-d H:i', $p['timestamp'])] + $p;
    }
}

// Charges with no vehicle cannot be attributed to a car; say so instead of hiding them.
$unassigned = ['charges' => 0, 'kwh' => 0.0];
if ($vehicleFilter === null) {
    $sql = "SELECT COUNT(*) AS n, COALESCE(SUM(consumedKwh), 0) AS kwh FROM charges
            WHERE vehicleId IS NULL AND stoppedAt IS NOT NULL AND consumedKwh > 0";
    $args = [];
    if ($dateFilters) { $sql .= " AND date(stoppedAt) >= ? AND date(stoppedAt) <= ?"; $args = [$dateFilters['start'], $dateFilters['end']]; }
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unassigned = ['charges' => (int) $row['n'], 'kwh' => round((float) $row['kwh'], 1)];
}

$json = json_encode(['vehicles' => $vehicles, 'series' => $series, 'unassigned' => $unassigned]);
ApiCache::respond($_effCacheKey, $json);
