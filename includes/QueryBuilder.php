<?php
/**
 * Query Builder for SparkSpend
 *
 * Provides static methods for common database query patterns.
 * Uses prepared statements to prevent SQL injection and reduce code duplication.
 *
 * Handles both internal charges (charges table) and external charges (ext_charges table)
 * with proper datetime parsing for ISO 8601 format compatibility.
 */

class QueryBuilder {

    /**
     * Get all vehicles
     *
     * @param PDO $db Database connection
     * @return array Array of vehicle records with id and vehicleName
     */
    public static function selectAllVehicles(PDO $db): array {
        $query = "SELECT id, vehicleName FROM vehicles ORDER BY vehicleName";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get vehicle by ID
     *
     * @param PDO $db Database connection
     * @param int $id Vehicle ID
     * @return array|null Vehicle record or null if not found
     */
    public static function selectVehicleById(PDO $db, int $id): ?array {
        $query = "SELECT id, vehicleName FROM vehicles WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all providers
     *
     * @param PDO $db Database connection
     * @return array Array of provider records with id and providerName
     */
    public static function selectAllProviders(PDO $db): array {
        $query = "SELECT id, providerName FROM provideres ORDER BY providerName";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Parse date range string in format "YYYY-MM-DD til YYYY-MM-DD"
     *
     * @param string $dateRange Date range string
     * @return array Array with 'start' and 'end' keys containing dates
     * @throws Exception If format is invalid
     */
    public static function parseDateRange(string $dateRange): array {
        $dates = [];

        // Try different separators
        if (strpos($dateRange, ' til ') !== false) {
            $dates = explode(' til ', $dateRange);
        } elseif (strpos($dateRange, ' to ') !== false) {
            $dates = explode(' to ', $dateRange);
        } else {
            throw new Exception("Invalid date range format. Use 'YYYY-MM-DD til YYYY-MM-DD'");
        }

        if (count($dates) !== 2) {
            throw new Exception("Invalid date range format. Use 'YYYY-MM-DD til YYYY-MM-DD'");
        }

        $start = trim($dates[0]);
        $end = trim($dates[1]);

        // Validate date format (basic YYYY-MM-DD check)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            throw new Exception("Invalid date format. Use YYYY-MM-DD");
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Get cost trend data (internal + external charges combined)
     *
     * Aggregates charges by time period (day, week, month).
     * Returns data grouped by internal vs external source.
     *
     * Filters:
     * - vehicleId (optional): Filter by specific vehicle
     * - dateRange (optional): "YYYY-MM-DD til YYYY-MM-DD"
     * - groupBy (default: 'week'): 'day', 'week', or 'month'
     *
     * @param PDO $db Database connection
     * @param array $filters Filter parameters
     * @return array Cost trend records with date, source, total_kwh, total_cost, charge_count
     */
    public static function getCostTrend(PDO $db, array $filters): array {
        $vehicleId = $filters['vehicleId'] ?? null;
        $dateRange = $filters['dateRange'] ?? '';
        $groupBy = $filters['groupBy'] ?? 'week';
        $showZeroKwh = $filters['showZeroKwh'] ?? true;

        // Parse date range if provided
        $startDate = null;
        $endDate = null;
        if (!empty($dateRange)) {
            $dates = self::parseDateRange($dateRange);
            $startDate = $dates['start'];
            $endDate = $dates['end'];
        }

        // Determine SQL date format based on groupBy
        $dateFormat = match($groupBy) {
            'day' => "'%Y-%m-%d'",
            'month' => "'%Y-%m'",
            'week' => "'%Y-%W'", // ISO week number
            default => "'%Y-%W'"
        };

        $trends = [];

        // ===== INTERNAL CHARGES (charges table) =====
        $queryInternal = "SELECT
            strftime({$dateFormat}, createdAt) as date,
            'internal' as source,
            COUNT(*) as charge_count,
            COALESCE(SUM(consumedKwh), 0) as total_kwh,
            COALESCE(SUM(cost), 0) as total_cost
        FROM charges
        WHERE 1=1";

        $paramsInternal = [];

        if ($vehicleId !== null) {
            $queryInternal .= " AND vehicleId = ?";
            $paramsInternal[] = $vehicleId;
        }

        if ($startDate !== null && $endDate !== null) {
            $queryInternal .= " AND DATE(createdAt) BETWEEN ? AND ?";
            $paramsInternal[] = $startDate;
            $paramsInternal[] = $endDate;
        }

        if (!$showZeroKwh) {
            $queryInternal .= " AND consumedKwh > 0";
        }

        $queryInternal .= " GROUP BY strftime({$dateFormat}, createdAt) ORDER BY date ASC";

        $stmtInternal = $db->prepare($queryInternal);
        $stmtInternal->execute($paramsInternal);
        $chargesInternal = $stmtInternal->fetchAll();

        // ===== EXTERNAL CHARGES (ext_charges table) =====
        // Note: We can't use date() function in SQL for ISO 8601 format, so we fetch all and filter in PHP
        $queryExternal = "SELECT
            datetime,
            'external' as source,
            COUNT(*) as charge_count,
            COALESCE(SUM(kwh), 0) as total_kwh,
            COALESCE(SUM(pris), 0) as total_cost
        FROM ext_charges
        WHERE 1=1";

        $paramsExternal = [];

        if ($vehicleId !== null) {
            $queryExternal .= " AND vehicleId = ?";
            $paramsExternal[] = $vehicleId;
        }

        // Don't filter by date in SQL - we'll do it in PHP
        $queryExternal .= " GROUP BY datetime ORDER BY datetime ASC";

        $stmtExternal = $db->prepare($queryExternal);
        $stmtExternal->execute($paramsExternal);
        $chargesExternalRaw = $stmtExternal->fetchAll();

        // Filter external charges by date in PHP (since SQLite can't parse ISO 8601 with timezone)
        $chargesExternal = [];
        if ($startDate !== null && $endDate !== null) {
            $startTime = strtotime($startDate);
            $endTime = strtotime($endDate . ' 23:59:59');

            $chargesExternal = array_filter($chargesExternalRaw, function($charge) use ($startTime, $endTime, $showZeroKwh) {
                $chargeTime = strtotime($charge['datetime']);
                $inDateRange = $chargeTime >= $startTime && $chargeTime <= $endTime;
                $passesKwhFilter = $showZeroKwh || floatval($charge['total_kwh']) > 0;
                return $inDateRange && $passesKwhFilter;
            });
        } else {
            // Filter by kWh requirement if date range not specified
            $chargesExternal = $showZeroKwh
                ? $chargesExternalRaw
                : array_filter($chargesExternalRaw, function($charge) {
                    return floatval($charge['kwh']) > 0;
                });
        }

        // Convert external charge dates and group them
        $externalGrouped = [];
        foreach ($chargesExternal as $charge) {
            $chargeDate = $charge['datetime'];
            $dateObj = DateTime::createFromFormat(DateTime::ISO8601, $chargeDate);
            if (!$dateObj) {
                $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $chargeDate);
            }

            if ($dateObj) {
                $groupedDate = match($groupBy) {
                    'day' => $dateObj->format('Y-m-d'),
                    'month' => $dateObj->format('Y-m'),
                    'week' => $dateObj->format('Y-W'),
                    default => $dateObj->format('Y-W')
                };

                if (!isset($externalGrouped[$groupedDate])) {
                    $externalGrouped[$groupedDate] = [
                        'date' => $groupedDate,
                        'source' => 'external',
                        'charge_count' => 0,
                        'total_kwh' => 0,
                        'total_cost' => 0
                    ];
                }

                $externalGrouped[$groupedDate]['charge_count'] += intval($charge['charge_count']);
                $externalGrouped[$groupedDate]['total_kwh'] += floatval($charge['total_kwh']);
                $externalGrouped[$groupedDate]['total_cost'] += floatval($charge['total_cost']);
            }
        }

        // Combine internal and external trends
        $allTrends = [];
        foreach ($chargesInternal as $record) {
            $allTrends[] = [
                'date' => $record['date'],
                'source' => 'internal',
                'charge_count' => intval($record['charge_count']),
                'total_kwh' => floatval($record['total_kwh']),
                'total_cost' => floatval($record['total_cost'])
            ];
        }

        foreach ($externalGrouped as $record) {
            $allTrends[] = $record;
        }

        return $allTrends;
    }

    /**
     * Get cost statistics for selected period
     *
     * Returns min, max, average cost and cost per kWh metrics.
     *
     * Filters:
     * - vehicleId (optional): Filter by specific vehicle
     * - dateRange (optional): "YYYY-MM-DD til YYYY-MM-DD"
     *
     * @param PDO $db Database connection
     * @param array $filters Filter parameters
     * @return array Statistics with min, max, avg, total values
     */
    public static function getCostStatistics(PDO $db, array $filters): array {
        $vehicleId = $filters['vehicleId'] ?? null;
        $dateRange = $filters['dateRange'] ?? '';
        $showZeroKwh = $filters['showZeroKwh'] ?? true;

        // Parse date range if provided
        $startDate = null;
        $endDate = null;
        if (!empty($dateRange)) {
            $dates = self::parseDateRange($dateRange);
            $startDate = $dates['start'];
            $endDate = $dates['end'];
        }

        $stats = [
            'total_charges' => 0,
            'total_kwh' => 0,
            'total_cost' => 0,
            'min_cost' => null,
            'max_cost' => null,
            'avg_cost_per_charge' => 0,
            'avg_cost_per_kwh' => 0,
            'internal_kwh' => 0,
            'internal_cost' => 0,
            'internal_charges' => 0,
            'external_kwh' => 0,
            'external_cost' => 0,
            'external_charges' => 0
        ];

        // ===== INTERNAL CHARGES =====
        $queryInternal = "SELECT
            COUNT(*) as charge_count,
            COALESCE(SUM(consumedKwh), 0) as total_kwh,
            COALESCE(SUM(cost), 0) as total_cost,
            MIN(cost) as min_cost,
            MAX(cost) as max_cost,
            AVG(cost) as avg_cost
        FROM charges
        WHERE 1=1";

        $paramsInternal = [];

        if ($vehicleId !== null) {
            $queryInternal .= " AND vehicleId = ?";
            $paramsInternal[] = $vehicleId;
        }

        if ($startDate !== null && $endDate !== null) {
            $queryInternal .= " AND DATE(createdAt) BETWEEN ? AND ?";
            $paramsInternal[] = $startDate;
            $paramsInternal[] = $endDate;
        }

        if (!$showZeroKwh) {
            $queryInternal .= " AND consumedKwh > 0";
        }

        $stmtInternal = $db->prepare($queryInternal);
        $stmtInternal->execute($paramsInternal);
        $internalStats = $stmtInternal->fetch();

        // ===== EXTERNAL CHARGES =====
        // Fetch raw external charges (not aggregated) so we can filter them properly
        $queryExternal = "SELECT
            id,
            datetime,
            kwh,
            pris
        FROM ext_charges
        WHERE 1=1";

        $paramsExternal = [];

        if ($vehicleId !== null) {
            $queryExternal .= " AND vehicleId = ?";
            $paramsExternal[] = $vehicleId;
        }

        $stmtExternal = $db->prepare($queryExternal);
        $stmtExternal->execute($paramsExternal);
        $externalChargesRaw = $stmtExternal->fetchAll();

        // Filter external charges by date in PHP and aggregate
        $externalStats = [
            'charge_count' => 0,
            'total_kwh' => 0,
            'total_cost' => 0,
            'min_cost' => null,
            'max_cost' => null,
            'avg_cost' => 0
        ];

        if (!empty($externalChargesRaw)) {
            $externalCharges = [];
            $costs = [];

            foreach ($externalChargesRaw as $charge) {
                $chargeTime = strtotime($charge['datetime']);

                // If date range specified, filter by date
                if ($startDate !== null && $endDate !== null) {
                    $startTime = strtotime($startDate);
                    $endTime = strtotime($endDate . ' 23:59:59');
                    if ($chargeTime < $startTime || $chargeTime > $endTime) {
                        continue;
                    }
                }

                // Filter out zero kWh charges if requested
                if (!$showZeroKwh && floatval($charge['kwh']) == 0) {
                    continue;
                }

                $externalCharges[] = $charge;
                $costs[] = floatval($charge['pris']);
            }

            if (!empty($externalCharges)) {
                $externalStats['charge_count'] = count($externalCharges);
                $externalStats['total_kwh'] = array_sum(array_column($externalCharges, 'kwh'));
                $externalStats['total_cost'] = array_sum(array_column($externalCharges, 'pris'));
                $externalStats['min_cost'] = min($costs);
                $externalStats['max_cost'] = max($costs);
                $externalStats['avg_cost'] = $externalStats['total_cost'] / $externalStats['charge_count'];
            }
        }

        // Combine stats from both sources
        $stats['internal_charges'] = intval($internalStats['charge_count'] ?? 0);
        $stats['internal_kwh'] = floatval($internalStats['total_kwh'] ?? 0);
        $stats['internal_cost'] = floatval($internalStats['total_cost'] ?? 0);

        $stats['external_charges'] = intval($externalStats['charge_count']);
        $stats['external_kwh'] = floatval($externalStats['total_kwh']);
        $stats['external_cost'] = floatval($externalStats['total_cost']);

        $stats['total_charges'] = $stats['internal_charges'] + $stats['external_charges'];
        $stats['total_kwh'] = $stats['internal_kwh'] + $stats['external_kwh'];
        $stats['total_cost'] = $stats['internal_cost'] + $stats['external_cost'];

        // Calculate min/max/avg across both sources
        $allCosts = [];
        if ($internalStats['min_cost'] !== null) {
            $allCosts[] = $internalStats['min_cost'];
            $allCosts[] = $internalStats['max_cost'];
        }
        if ($externalStats['min_cost'] !== null) {
            $allCosts[] = $externalStats['min_cost'];
            $allCosts[] = $externalStats['max_cost'];
        }

        if (!empty($allCosts)) {
            $stats['min_cost'] = round(min($allCosts), 2);
            $stats['max_cost'] = round(max($allCosts), 2);
        }

        if ($stats['total_charges'] > 0) {
            $stats['avg_cost_per_charge'] = round($stats['total_cost'] / $stats['total_charges'], 2);
        }

        if ($stats['total_kwh'] > 0) {
            $stats['avg_cost_per_kwh'] = round($stats['total_cost'] / $stats['total_kwh'], 3);
        }

        return $stats;
    }

    /**
     * Get vehicle cost comparison data
     *
     * Aggregates cost metrics for each vehicle across all charges.
     * Includes internal vs external breakdown.
     *
     * Filters:
     * - dateRange (optional): "YYYY-MM-DD til YYYY-MM-DD"
     *
     * @param PDO $db Database connection
     * @param array $filters Filter parameters
     * @return array Array of vehicles with aggregated cost data
     */
    public static function getVehicleCostComparison(PDO $db, array $filters): array {
        $dateRange = $filters['dateRange'] ?? '';

        // Parse date range if provided
        $startDate = null;
        $endDate = null;
        if (!empty($dateRange)) {
            $dates = self::parseDateRange($dateRange);
            $startDate = $dates['start'];
            $endDate = $dates['end'];
        }

        // Get all vehicles
        $vehicles = self::selectAllVehicles($db);
        $vehicleComparison = [];

        foreach ($vehicles as $vehicle) {
            $vehicleId = $vehicle['id'];

            // Use existing statistics method to get cost data per vehicle
            $vehicleFilters = ['vehicleId' => $vehicleId];
            if (!empty($dateRange)) {
                $vehicleFilters['dateRange'] = $dateRange;
            }

            $stats = self::getCostStatistics($db, $vehicleFilters);

            $vehicleComparison[] = [
                'vehicleId' => $vehicleId,
                'vehicleName' => $vehicle['vehicleName'],
                'charge_count' => $stats['total_charges'],
                'total_kwh' => round($stats['total_kwh'], 2),
                'total_cost' => round($stats['total_cost'], 2),
                'avg_cost_per_kwh' => $stats['avg_cost_per_kwh'],
                'internal_kwh' => round($stats['internal_kwh'], 2),
                'internal_cost' => round($stats['internal_cost'], 2),
                'internal_charges' => $stats['internal_charges'],
                'external_kwh' => round($stats['external_kwh'], 2),
                'external_cost' => round($stats['external_cost'], 2),
                'external_charges' => $stats['external_charges'],
                'internal_percentage' => $stats['total_kwh'] > 0
                    ? round(($stats['internal_kwh'] / $stats['total_kwh']) * 100, 1)
                    : 0,
                'external_percentage' => $stats['total_kwh'] > 0
                    ? round(($stats['external_kwh'] / $stats['total_kwh']) * 100, 1)
                    : 0
            ];
        }

        return $vehicleComparison;
    }

    /**
     * Update internal charge vehicle assignment
     *
     * @param PDO $db Database connection
     * @param array $data Array with keys: id, vehicleId
     * @return bool True if update successful
     */
    public static function updateInternalCharge(PDO $db, array $data): bool {
        $query = "UPDATE charges SET vehicleId = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        return $stmt->execute([
            $data['vehicleId'],
            $data['id']
        ]);
    }

    /**
     * Update external charge details
     *
     * @param PDO $db Database connection
     * @param array $data Array with keys: id, vehicleId, kwh, cost, chargeDate, providerId
     * @return bool True if update successful
     */
    public static function updateExternalCharge(PDO $db, array $data): bool {
        $query = "UPDATE ext_charges SET vehicleId = ?, providerId = ?, kwh = ?, pris = ?, datetime = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        return $stmt->execute([
            $data['vehicleId'],
            $data['providerId'] ?? 0,
            $data['kwh'] ?? 0,
            $data['cost'] ?? 0,
            $data['chargeDate'] ?? null,
            $data['id']
        ]);
    }

    /**
     * Insert a new external charge record into ext_charges table
     *
     * @param PDO $db Database connection
     * @param array $data Array with keys: vehicleId, providerId, kwh, cost, chargeDate
     * @return array Array with keys: success (bool), id (int|null)
     */
    public static function insertExternalCharge(PDO $db, array $data): array {
        $query = "INSERT INTO ext_charges (vehicleId, providerId, kwh, pris, datetime) VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $ok = $stmt->execute([
            $data['vehicleId'],
            $data['providerId'] ?? 0,
            $data['kwh'] ?? 0,
            $data['cost'] ?? 0,
            $data['chargeDate'] ?? null,
        ]);
        return ['success' => $ok, 'id' => $ok ? (int) $db->lastInsertId() : null];
    }

    /**
     * Delete an external charge record from ext_charges table
     *
     * @param PDO $db Database connection
     * @param int $id Charge ID to delete
     * @return bool True if delete successful
     */
    public static function deleteExternalCharge(PDO $db, int $id): bool {
        $stmt = $db->prepare("DELETE FROM ext_charges WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Insert vehicle odometer reading into vehicle_charges table
     *
     * @param PDO $db Database connection
     * @param array $data Array with keys: vehicleId, timestamp, odometer
     * @return bool True if insert successful
     */
    public static function insertVehicleData(PDO $db, array $data): bool {
        $query = "INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (?, ?, ?)";
        $stmt = $db->prepare($query);
        return $stmt->execute([
            $data['vehicleId'],
            $data['timestamp'],
            $data['odometer']
        ]);
    }
}
?>
