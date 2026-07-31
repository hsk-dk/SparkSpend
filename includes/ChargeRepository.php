<?php
/**
 * Charge Repository
 *
 * Query and mutation operations for internal charges (charges table)
 * and external charges (ext_charges table). Includes analytics methods
 * for cost trends, statistics, and vehicle comparisons.
 */

require_once __DIR__ . '/DateHelper.php';

class ChargeRepository {

    // =========================================================================
    // CRUD Operations
    // =========================================================================

    public static function updateInternal(PDO $db, array $data): bool {
        $stmt = $db->prepare("UPDATE charges SET vehicleId = ? WHERE id = ?");
        return $stmt->execute([$data['vehicleId'], $data['id']]);
    }

    public static function updateExternal(PDO $db, array $data): bool {
        $stmt = $db->prepare("UPDATE ext_charges SET vehicleId = ?, providerId = ?, kwh = ?, pris = ?, datetime = ? WHERE id = ?");
        return $stmt->execute([
            $data['vehicleId'],
            $data['providerId'] ?? 0,
            $data['kwh'] ?? 0,
            $data['cost'] ?? 0,
            $data['chargeDate'] ?? null,
            $data['id']
        ]);
    }

    public static function insertExternal(PDO $db, array $data): array {
        $stmt = $db->prepare("INSERT INTO ext_charges (vehicleId, providerId, kwh, pris, datetime) VALUES (?, ?, ?, ?, ?)");
        $ok = $stmt->execute([
            $data['vehicleId'],
            $data['providerId'] ?? 0,
            $data['kwh'] ?? 0,
            $data['cost'] ?? 0,
            $data['chargeDate'] ?? null,
        ]);
        return ['success' => $ok, 'id' => $ok ? (int) $db->lastInsertId() : null];
    }

    public static function deleteExternal(PDO $db, int $id): bool {
        $stmt = $db->prepare("DELETE FROM ext_charges WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Fetch external charges within a date range, optionally filtered by vehicle.
     */
    public static function getExternal(
        PDO $db,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $vehicleId = null,
        bool $includeZeroKwh = true
    ): array {
        $where  = [];
        $params = [];

        if ($fromDate !== null) {
            $where[]  = "DATE(datetime) >= ?";
            $params[] = $fromDate;
        }
        if ($toDate !== null) {
            $where[]  = "DATE(datetime) <= ?";
            $params[] = $toDate;
        }
        if ($vehicleId !== null) {
            $where[]  = "vehicleId = ?";
            $params[] = $vehicleId;
        }
        if (!$includeZeroKwh) {
            $where[] = "kwh > 0";
        }

        $sql = "SELECT datetime, kwh, pris, vehicleId FROM ext_charges";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY datetime ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Analytics
    // =========================================================================

    /**
     * Get cost trend data (internal + external charges combined).
     * Aggregates charges by time period (day, week, month).
     */
    public static function getCostTrend(PDO $db, array $filters): array {
        $vehicleId = $filters['vehicleId'] ?? null;
        $dateRange = $filters['dateRange'] ?? '';
        $groupBy = $filters['groupBy'] ?? 'week';
        $showZeroKwh = $filters['showZeroKwh'] ?? true;

        $startDate = null;
        $endDate = null;
        if (!empty($dateRange)) {
            $dates = DateHelper::parseDateRange($dateRange);
            $startDate = $dates['start'];
            $endDate = $dates['end'];
        }

        // Internal charges
        $queryInternal = "SELECT startedAt, stoppedAt, consumedKwh, cost FROM charges WHERE 1=1";
        $paramsInternal = [];

        if ($vehicleId !== null) {
            $queryInternal .= " AND vehicleId = ?";
            $paramsInternal[] = $vehicleId;
        }
        if ($startDate !== null && $endDate !== null) {
            $queryInternal .= " AND DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?";
            $paramsInternal[] = $endDate;
            $paramsInternal[] = $startDate;
        }
        if (!$showZeroKwh) {
            $queryInternal .= " AND consumedKwh > 0";
        }
        $queryInternal .= " ORDER BY startedAt ASC";

        $stmtInternal = $db->prepare($queryInternal);
        $stmtInternal->execute($paramsInternal);

        $internalGrouped = [];
        foreach ($stmtInternal->fetchAll() as $record) {
            $days = DateHelper::splitChargeByDays(
                $record['startedAt'], $record['stoppedAt'],
                floatval($record['consumedKwh']), floatval($record['cost'])
            );
            foreach ($days as $day => $vals) {
                if ($startDate !== null && ($day < $startDate || $day > $endDate)) continue;
                $dateObj = date_create($day);
                if (!$dateObj) continue;
                $groupedDate = match($groupBy) {
                    'day'   => $dateObj->format('Y-m-d'),
                    'month' => $dateObj->format('Y-m'),
                    default => $dateObj->format('o-\WW'),
                };
                if (!isset($internalGrouped[$groupedDate])) {
                    $internalGrouped[$groupedDate] = ['date' => $groupedDate, 'source' => 'internal', 'charge_count' => 0, 'total_kwh' => 0.0, 'total_cost' => 0.0];
                }
                $internalGrouped[$groupedDate]['charge_count'] += $vals['count'];
                $internalGrouped[$groupedDate]['total_kwh']    += $vals['kwh'];
                $internalGrouped[$groupedDate]['total_cost']   += $vals['cost'];
            }
        }

        // External charges
        $queryExternal = "SELECT datetime, 'external' as source, 1 as charge_count, kwh as total_kwh, pris as total_cost FROM ext_charges WHERE 1=1";
        $paramsExternal = [];

        if ($vehicleId !== null) {
            $queryExternal .= " AND vehicleId = ?";
            $paramsExternal[] = $vehicleId;
        }
        if ($startDate !== null && $endDate !== null) {
            $queryExternal .= " AND DATE(datetime) >= ? AND DATE(datetime) <= ?";
            $paramsExternal[] = $startDate;
            $paramsExternal[] = $endDate;
        }
        if (!$showZeroKwh) {
            $queryExternal .= " AND kwh > 0";
        }
        $queryExternal .= " ORDER BY datetime ASC";

        $stmtExternal = $db->prepare($queryExternal);
        $stmtExternal->execute($paramsExternal);

        $externalGrouped = [];
        foreach ($stmtExternal->fetchAll() as $charge) {
            $dateObj = date_create($charge['datetime']);
            if (!$dateObj) continue;
            $groupedDate = match($groupBy) {
                'day'   => $dateObj->format('Y-m-d'),
                'month' => $dateObj->format('Y-m'),
                default => $dateObj->format('o-\WW'),
            };
            if (!isset($externalGrouped[$groupedDate])) {
                $externalGrouped[$groupedDate] = ['date' => $groupedDate, 'source' => 'external', 'charge_count' => 0, 'total_kwh' => 0, 'total_cost' => 0];
            }
            $externalGrouped[$groupedDate]['charge_count'] += intval($charge['charge_count']);
            $externalGrouped[$groupedDate]['total_kwh'] += floatval($charge['total_kwh']);
            $externalGrouped[$groupedDate]['total_cost'] += floatval($charge['total_cost']);
        }

        $allTrends = array_values($internalGrouped);
        foreach ($externalGrouped as $record) { $allTrends[] = $record; }
        return $allTrends;
    }

    /**
     * Get cost statistics for selected period.
     */
    public static function getCostStatistics(PDO $db, array $filters): array {
        $vehicleId = $filters['vehicleId'] ?? null;
        $dateRange = $filters['dateRange'] ?? '';
        $showZeroKwh = $filters['showZeroKwh'] ?? true;

        $startDate = null;
        $endDate = null;
        if (!empty($dateRange)) {
            $dates = DateHelper::parseDateRange($dateRange);
            $startDate = $dates['start'];
            $endDate = $dates['end'];
        }

        $stats = ['total_charges' => 0, 'total_kwh' => 0, 'total_cost' => 0, 'min_cost' => null, 'max_cost' => null,
                  'avg_cost_per_charge' => 0, 'avg_cost_per_kwh' => 0, 'internal_kwh' => 0, 'internal_cost' => 0,
                  'internal_charges' => 0, 'external_kwh' => 0, 'external_cost' => 0, 'external_charges' => 0];

        // Internal
        $qi = "SELECT COUNT(*) as charge_count, COALESCE(SUM(consumedKwh),0) as total_kwh, COALESCE(SUM(cost),0) as total_cost, MIN(cost) as min_cost, MAX(cost) as max_cost FROM charges WHERE 1=1";
        $pi = [];
        if ($vehicleId !== null) { $qi .= " AND vehicleId = ?"; $pi[] = $vehicleId; }
        if ($startDate !== null) { $qi .= " AND DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?"; $pi[] = $endDate; $pi[] = $startDate; }
        if (!$showZeroKwh) { $qi .= " AND consumedKwh > 0"; }
        $internalStats = $db->prepare($qi); $internalStats->execute($pi); $internalStats = $internalStats->fetch();

        // External
        $qe = "SELECT COUNT(*) as charge_count, COALESCE(SUM(kwh),0) as total_kwh, COALESCE(SUM(pris),0) as total_cost, MIN(pris) as min_cost, MAX(pris) as max_cost FROM ext_charges WHERE 1=1";
        $pe = [];
        if ($vehicleId !== null) { $qe .= " AND vehicleId = ?"; $pe[] = $vehicleId; }
        if ($startDate !== null) { $qe .= " AND DATE(datetime) >= ? AND DATE(datetime) <= ?"; $pe[] = $startDate; $pe[] = $endDate; }
        if (!$showZeroKwh) { $qe .= " AND kwh > 0"; }
        $externalStats = $db->prepare($qe); $externalStats->execute($pe); $externalStats = $externalStats->fetch(PDO::FETCH_ASSOC);

        $stats['internal_charges'] = intval($internalStats['charge_count'] ?? 0);
        $stats['internal_kwh'] = floatval($internalStats['total_kwh'] ?? 0);
        $stats['internal_cost'] = floatval($internalStats['total_cost'] ?? 0);
        $stats['external_charges'] = intval($externalStats['charge_count'] ?? 0);
        $stats['external_kwh'] = floatval($externalStats['total_kwh'] ?? 0);
        $stats['external_cost'] = floatval($externalStats['total_cost'] ?? 0);
        $stats['total_charges'] = $stats['internal_charges'] + $stats['external_charges'];
        $stats['total_kwh'] = $stats['internal_kwh'] + $stats['external_kwh'];
        $stats['total_cost'] = $stats['internal_cost'] + $stats['external_cost'];

        $allCosts = [];
        if ($internalStats['min_cost'] !== null) { $allCosts[] = $internalStats['min_cost']; $allCosts[] = $internalStats['max_cost']; }
        if ($externalStats['min_cost'] !== null) { $allCosts[] = $externalStats['min_cost']; $allCosts[] = $externalStats['max_cost']; }
        if (!empty($allCosts)) { $stats['min_cost'] = round(min($allCosts), 2); $stats['max_cost'] = round(max($allCosts), 2); }
        if ($stats['total_charges'] > 0) { $stats['avg_cost_per_charge'] = round($stats['total_cost'] / $stats['total_charges'], 2); }
        if ($stats['total_kwh'] > 0) { $stats['avg_cost_per_kwh'] = round($stats['total_cost'] / $stats['total_kwh'], 3); }

        return $stats;
    }

    /**
     * Get vehicle cost comparison data.
     */
    public static function getVehicleCostComparison(PDO $db, array $filters): array {
        $dateRange = $filters['dateRange'] ?? '';
        $startDate = null;
        $endDate = null;
        if (!empty($dateRange)) {
            $dates = DateHelper::parseDateRange($dateRange);
            $startDate = $dates['start'];
            $endDate = $dates['end'];
        }

        require_once __DIR__ . '/VehicleRepository.php';
        $vehicles = VehicleRepository::getAll($db);
        if (empty($vehicles)) return [];

        $map = [];
        foreach ($vehicles as $v) {
            $map[$v['id']] = ['vehicleId' => $v['id'], 'vehicleName' => $v['vehicleName'],
                'internal_charges' => 0, 'internal_kwh' => 0.0, 'internal_cost' => 0.0,
                'external_charges' => 0, 'external_kwh' => 0.0, 'external_cost' => 0.0];
        }

        $qInt = "SELECT vehicleId, COUNT(*) as charge_count, COALESCE(SUM(consumedKwh),0) as total_kwh, COALESCE(SUM(cost),0) as total_cost FROM charges WHERE consumedKwh > 0";
        $pInt = [];
        if ($startDate !== null) { $qInt .= " AND DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?"; $pInt[] = $endDate; $pInt[] = $startDate; }
        $qInt .= " GROUP BY vehicleId";
        $stmt = $db->prepare($qInt); $stmt->execute($pInt);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vid = $row['vehicleId'];
            if (isset($map[$vid])) {
                $map[$vid]['internal_charges'] = intval($row['charge_count']);
                $map[$vid]['internal_kwh'] = floatval($row['total_kwh']);
                $map[$vid]['internal_cost'] = floatval($row['total_cost']);
            }
        }

        $qExt = "SELECT vehicleId, kwh, pris FROM ext_charges WHERE kwh > 0";
        $pExt = [];
        if ($startDate !== null) { $qExt .= " AND DATE(datetime) >= ? AND DATE(datetime) <= ?"; $pExt[] = $startDate; $pExt[] = $endDate; }
        $stmt = $db->prepare($qExt); $stmt->execute($pExt);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vid = $row['vehicleId'];
            if (!isset($map[$vid])) continue;
            $map[$vid]['external_charges']++;
            $map[$vid]['external_kwh'] += floatval($row['kwh']);
            $map[$vid]['external_cost'] += floatval($row['pris']);
        }

        $result = [];
        foreach ($map as $v) {
            $totalKwh = $v['internal_kwh'] + $v['external_kwh'];
            $totalCost = $v['internal_cost'] + $v['external_cost'];
            $totalCharges = $v['internal_charges'] + $v['external_charges'];
            $result[] = [
                'vehicleId' => $v['vehicleId'], 'vehicleName' => $v['vehicleName'],
                'charge_count' => $totalCharges, 'total_kwh' => round($totalKwh, 2), 'total_cost' => round($totalCost, 2),
                'avg_cost_per_kwh' => $totalKwh > 0 ? round($totalCost / $totalKwh, 3) : 0,
                'internal_kwh' => round($v['internal_kwh'], 2), 'internal_cost' => round($v['internal_cost'], 2), 'internal_charges' => $v['internal_charges'],
                'external_kwh' => round($v['external_kwh'], 2), 'external_cost' => round($v['external_cost'], 2), 'external_charges' => $v['external_charges'],
                'internal_percentage' => $totalKwh > 0 ? round(($v['internal_kwh'] / $totalKwh) * 100, 1) : 0,
                'external_percentage' => $totalKwh > 0 ? round(($v['external_kwh'] / $totalKwh) * 100, 1) : 0,
            ];
        }
        return $result;
    }
}
