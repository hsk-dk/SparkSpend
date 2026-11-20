<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
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

return static function (Request $request): Response {
    // ─── 5-minute file cache ─────────────────────────────────────────────────────────────
    $cacheKey = 'efficiency_' . md5(($request->query['filter'] ?? '') . '|' . ($request->query['dateRange'] ?? ''));
    if (($hit = ApiCache::fetch($cacheKey, CacheHelper::TTL_MEDIUM)) !== null) return Response::json($hit);
    // ─────────────────────────────────────────────────────────────────────────────

    $db = DatabaseManager::getChargesDb();

    $filter    = $request->query['filter'] ?? 'all';
    $dateRange = $request->query['dateRange'] ?? '';
    $vehicleFilter = ($filter !== 'all' && $filter !== '') ? intval($filter) : null;

    // A non-empty but unparseable range is a clear filter intent: reject it (400) rather
    // than silently returning everything, which would look like a valid but wrong result.
    $dateFilters = null;
    if ($dateRange !== '') {
        try {
            $dateFilters = DateHelper::parseDateRange($dateRange);
        } catch (\Throwable $e) {
            Http::fail('Ugyldigt dateRange-format. Forventet: "YYYY-MM-DD til YYYY-MM-DD".');
        }
    }
    $fromTs = $dateFilters ? strtotime($dateFilters['start'] . ' 00:00:00') : null;
    $toTs   = $dateFilters ? strtotime($dateFilters['end']   . ' 23:59:59') : null;

    // ─── Data (shared with the dashboard's EV card: includes/EfficiencyData.php) ──────────
    [$readingsByVehicle, $chargesByVehicle] = EfficiencyData::load($db, $vehicleFilter);

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

    return ApiCache::store($cacheKey, ['vehicles' => $vehicles, 'series' => $series, 'unassigned' => $unassigned]);
};
