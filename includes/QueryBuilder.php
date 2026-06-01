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
        $query = "SELECT id, providerName FROM providers ORDER BY providerName";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function updateVehicle(PDO $db, int $id, string $name): bool {
        $stmt = $db->prepare("UPDATE vehicles SET vehicleName = ? WHERE id = ?");
        return $stmt->execute([trim($name), $id]);
    }

    /**
     * Insert vehicle if it doesn't exist; update vehicleName when a non-empty name is given.
     * Used by receive_vehicle_data.php and update_monta_data.php to keep names current.
     */
    public static function upsertVehicle(PDO $db, int $id, string $name = ''): void {
        $name = trim($name);
        if ($name !== '') {
            $db->prepare(
                "INSERT INTO vehicles (id, vehicleName) VALUES (?, ?)
                 ON CONFLICT(id) DO UPDATE SET vehicleName = excluded.vehicleName"
            )->execute([$id, $name]);
        } else {
            // Ensure row exists with a placeholder; don't overwrite an existing name.
            $db->prepare(
                "INSERT OR IGNORE INTO vehicles (id, vehicleName) VALUES (?, ?)"
            )->execute([$id, 'Køretøj ' . $id]);
        }
    }

    public static function insertProvider(PDO $db, string $name): int {
        $stmt = $db->prepare("INSERT INTO providers (providerName) VALUES (?)");
        $stmt->execute([trim($name)]);
        return (int) $db->lastInsertId();
    }

    public static function updateProvider(PDO $db, int $id, string $name): bool {
        $stmt = $db->prepare("UPDATE providers SET providerName = ? WHERE id = ?");
        return $stmt->execute([trim($name), $id]);
    }

    public static function deleteProvider(PDO $db, int $id): bool {
        $stmt = $db->prepare("DELETE FROM providers WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public static function providerUsageCount(PDO $db, int $id): int {
        $stmt = $db->prepare("SELECT COUNT(*) FROM ext_charges WHERE providerId = ?");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
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
     * Proportionally split a charge session across calendar days.
     *
     * A session that runs from 22:00 to 02:00 spans two local calendar days, so
     * 50 % of kWh and cost belongs to each day. Timestamps are UTC ISO 8601 strings
     * (e.g. "2026-04-13T22:00:00Z"); PHP uses the app timezone (set via
     * date_default_timezone_set in configuration.php) for local midnight boundaries.
     *
     * Returns: ['Y-m-d' => ['kwh' => float, 'cost' => float, 'count' => int]]
     *   'count' is 1 on the day charging started, 0 on subsequent continuation days.
     */
    public static function splitChargeByDays(
        string $startedAt,
        string $stoppedAt,
        float  $totalKwh,
        float  $totalCost
    ): array {
        $startTs = strtotime($startedAt);
        $stopTs  = strtotime($stoppedAt);

        // Fallback: assign entirely to the start day
        if ($startTs === false || $stopTs === false || $stopTs <= $startTs) {
            $day = date('Y-m-d', $startTs ?: time());
            return [$day => ['kwh' => $totalKwh, 'cost' => $totalCost, 'count' => 1]];
        }

        $totalSeconds = $stopTs - $startTs;
        $result       = [];
        $cursor       = $startTs;
        $isFirstDay   = true;

        while ($cursor < $stopTs) {
            // Local midnight starting the next calendar day
            $nextMidnight = mktime(0, 0, 0,
                (int) date('n', $cursor),
                (int) date('j', $cursor) + 1,
                (int) date('Y', $cursor)
            );
            $sliceEnd = min($nextMidnight, $stopTs);
            $ratio    = ($sliceEnd - $cursor) / $totalSeconds;
            $day      = date('Y-m-d', $cursor);

            $result[$day] = [
                'kwh'   => $totalKwh  * $ratio,
                'cost'  => $totalCost * $ratio,
                'count' => $isFirstDay ? 1 : 0,
            ];

            $isFirstDay = false;
            $cursor     = $nextMidnight;
        }

        return $result;
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

        $trends = [];

        // ===== INTERNAL CHARGES (charges table) =====
        // Fetch raw per-session rows so sessions crossing midnight can be split
        // proportionally. The overlap filter (startedAt <= endDate AND stoppedAt >= startDate)
        // captures any session touching the range; PHP clips the split slices to
        // the requested dates so out-of-range fragments are discarded.
        $queryInternal = "SELECT startedAt, stoppedAt, consumedKwh, cost
        FROM charges
        WHERE 1=1";

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
        $chargesInternal = $stmtInternal->fetchAll();

        // Split each session across the days it spans, then collapse into the
        // requested time-period bucket (day / ISO week / month).
        $internalGrouped = [];
        foreach ($chargesInternal as $record) {
            $days = self::splitChargeByDays(
                $record['startedAt'],
                $record['stoppedAt'],
                floatval($record['consumedKwh']),
                floatval($record['cost'])
            );
            foreach ($days as $day => $vals) {
                // Discard fragments that fall outside the requested range
                if ($startDate !== null && ($day < $startDate || $day > $endDate)) {
                    continue;
                }
                $dateObj = date_create($day);
                if (!$dateObj) continue;
                $groupedDate = match($groupBy) {
                    'day'   => $dateObj->format('Y-m-d'),
                    'month' => $dateObj->format('Y-m'),
                    default => $dateObj->format('o-\WW'),
                };
                if (!isset($internalGrouped[$groupedDate])) {
                    $internalGrouped[$groupedDate] = [
                        'date'         => $groupedDate,
                        'source'       => 'internal',
                        'charge_count' => 0,
                        'total_kwh'    => 0.0,
                        'total_cost'   => 0.0,
                    ];
                }
                $internalGrouped[$groupedDate]['charge_count'] += $vals['count'];
                $internalGrouped[$groupedDate]['total_kwh']    += $vals['kwh'];
                $internalGrouped[$groupedDate]['total_cost']   += $vals['cost'];
            }
        }

        // ===== EXTERNAL CHARGES (ext_charges table) =====
        // Note: We can't use date() function in SQL for ISO 8601 format, so we fetch all and filter in PHP
        $queryExternal = "SELECT
            datetime,
            'external' as source,
            1 as charge_count,
            kwh as total_kwh,
            pris as total_cost
        FROM ext_charges
        WHERE 1=1";

        $paramsExternal = [];

        if ($vehicleId !== null) {
            $queryExternal .= " AND vehicleId = ?";
            $paramsExternal[] = $vehicleId;
        }

        // Don't filter by date in SQL - we'll do it in PHP
        $queryExternal .= " ORDER BY datetime ASC";

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
                    return floatval($charge['total_kwh']) > 0;
                });
        }

        // Convert external charge dates and group them
        $externalGrouped = [];
        foreach ($chargesExternal as $charge) {
            $chargeDate = $charge['datetime'];
            $dateObj = date_create($chargeDate);

            if ($dateObj) {
                $groupedDate = match($groupBy) {
                    'day'   => $dateObj->format('Y-m-d'),
                    'month' => $dateObj->format('Y-m'),
                    default => $dateObj->format('o-\WW'), // ISO year + ISO week, zero-padded
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
        $allTrends = array_values($internalGrouped);

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
            $queryInternal .= " AND DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?";
            $paramsInternal[] = $endDate;
            $paramsInternal[] = $startDate;
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

        $startDate = null;
        $endDate   = null;
        if (!empty($dateRange)) {
            $dates     = self::parseDateRange($dateRange);
            $startDate = $dates['start'];
            $endDate   = $dates['end'];
        }

        // Query 1: all vehicles
        $vehicles = self::selectAllVehicles($db);
        if (empty($vehicles)) return [];

        // Seed result map keyed by vehicleId
        $map = [];
        foreach ($vehicles as $v) {
            $map[$v['id']] = [
                'vehicleId'        => $v['id'],
                'vehicleName'      => $v['vehicleName'],
                'internal_charges' => 0,
                'internal_kwh'     => 0.0,
                'internal_cost'    => 0.0,
                'external_charges' => 0,
                'external_kwh'     => 0.0,
                'external_cost'    => 0.0,
            ];
        }

        // Query 2: aggregate internal charges grouped by vehicle
        $qInt  = "SELECT vehicleId,
                      COUNT(*) as charge_count,
                      COALESCE(SUM(consumedKwh), 0) as total_kwh,
                      COALESCE(SUM(cost), 0) as total_cost
                  FROM charges WHERE consumedKwh > 0";
        $pInt  = [];
        if ($startDate !== null) {
            $qInt   .= " AND DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?";
            $pInt[]  = $endDate;
            $pInt[]  = $startDate;
        }
        $qInt .= " GROUP BY vehicleId";
        $stmt  = $db->prepare($qInt);
        $stmt->execute($pInt);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vid = $row['vehicleId'];
            if (isset($map[$vid])) {
                $map[$vid]['internal_charges'] = intval($row['charge_count']);
                $map[$vid]['internal_kwh']     = floatval($row['total_kwh']);
                $map[$vid]['internal_cost']    = floatval($row['total_cost']);
            }
        }

        // Query 3: all external charges — filter in PHP (datetime uses Z-suffix ISO 8601
        // which SQLite date functions cannot parse reliably)
        $stmt    = $db->prepare("SELECT vehicleId, datetime, kwh, pris FROM ext_charges WHERE kwh > 0");
        $stmt->execute();
        $startTs = $startDate !== null ? strtotime($startDate) : null;
        $endTs   = $endDate   !== null ? strtotime($endDate . ' 23:59:59') : null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vid = $row['vehicleId'];
            if (!isset($map[$vid])) continue;
            if ($startTs !== null) {
                $t = strtotime($row['datetime']);
                if ($t < $startTs || $t > $endTs) continue;
            }
            $map[$vid]['external_charges']++;
            $map[$vid]['external_kwh']  += floatval($row['kwh']);
            $map[$vid]['external_cost'] += floatval($row['pris']);
        }

        // Build final result with derived totals
        $result = [];
        foreach ($map as $v) {
            $totalKwh     = $v['internal_kwh']  + $v['external_kwh'];
            $totalCost    = $v['internal_cost'] + $v['external_cost'];
            $totalCharges = $v['internal_charges'] + $v['external_charges'];
            $result[] = [
                'vehicleId'           => $v['vehicleId'],
                'vehicleName'         => $v['vehicleName'],
                'charge_count'        => $totalCharges,
                'total_kwh'           => round($totalKwh, 2),
                'total_cost'          => round($totalCost, 2),
                'avg_cost_per_kwh'    => $totalKwh > 0 ? round($totalCost / $totalKwh, 3) : 0,
                'internal_kwh'        => round($v['internal_kwh'], 2),
                'internal_cost'       => round($v['internal_cost'], 2),
                'internal_charges'    => $v['internal_charges'],
                'external_kwh'        => round($v['external_kwh'], 2),
                'external_cost'       => round($v['external_cost'], 2),
                'external_charges'    => $v['external_charges'],
                'internal_percentage' => $totalKwh > 0 ? round(($v['internal_kwh'] / $totalKwh) * 100, 1) : 0,
                'external_percentage' => $totalKwh > 0 ? round(($v['external_kwh'] / $totalKwh) * 100, 1) : 0,
            ];
        }

        return $result;
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

    /**
     * Compute daily kWh deltas from a cumulative-meter table using a LAG window function.
     *
     * The meter is expected to only go up (e.g. powerlogjord, powerloghus).
     * Negative deltas (meter resets) are clamped to 0.
     * The query fetches one extra day before $from so the first day in the range
     * gets a valid prior-day baseline.
     *
     * @param PDO    $db    SQLite connection (powerlog database)
     * @param string $table Table name — must be 'powerlogjord' or 'powerloghus' (whitelisted)
     * @param string $from  Start date YYYY-MM-DD (inclusive)
     * @param string $to    End date   YYYY-MM-DD (inclusive)
     * @return array  Associative array keyed by 'YYYY-MM-DD' => float kWh delta
     * @throws InvalidArgumentException if table name is not whitelisted
     */
    public static function lagDelta(PDO $db, string $table, string $from, string $to): array {
        static $allowed = ['powerlogjord', 'powerloghus'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException("lagDelta: table '$table' is not whitelisted");
        }

        $sql = "
            WITH daily_max AS (
                SELECT DATE(logdate) AS day,
                       MAX(kwh)      AS max_kwh
                FROM $table
                WHERE DATE(logdate) >= DATE(?, '-1 day')
                  AND DATE(logdate) <= ?
                GROUP BY DATE(logdate)
            ),
            daily_delta AS (
                SELECT day,
                       MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
                FROM daily_max
            )
            SELECT day, ROUND(delta_kwh, 2) AS kwh
            FROM daily_delta
            WHERE DATE(day) BETWEEN ? AND ?
            ORDER BY day
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$from, $to, $from, $to]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['day']] = floatval($row['kwh']);
        }
        return $result;
    }

    /**
     * Sum lagDelta results by month (YYYY-MM).
     *
     * @param PDO    $db    SQLite connection
     * @param string $table Table name (whitelisted by lagDelta)
     * @param string $from  Start date YYYY-MM-DD
     * @param string $to    End date   YYYY-MM-DD
     * @return array  Associative array keyed by 'YYYY-MM' => float total kWh
     */
    public static function lagDeltaByMonth(PDO $db, string $table, string $from, string $to): array {
        $daily = self::lagDelta($db, $table, $from, $to);
        $byMonth = [];
        foreach ($daily as $day => $kwh) {
            $month = substr($day, 0, 7);
            $byMonth[$month] = round(($byMonth[$month] ?? 0.0) + $kwh, 4);
        }
        // Round final values
        foreach ($byMonth as $m => $v) {
            $byMonth[$m] = round($v, 2);
        }
        return $byMonth;
    }

    /**
     * Sum lagDelta results by year (YYYY).
     *
     * @param PDO    $db    SQLite connection
     * @param string $table Table name (whitelisted by lagDelta)
     * @param string $from  Start date YYYY-MM-DD
     * @param string $to    End date   YYYY-MM-DD
     * @return array  Associative array keyed by 'YYYY' => float total kWh
     */
    public static function lagDeltaByYear(PDO $db, string $table, string $from, string $to): array {
        $daily = self::lagDelta($db, $table, $from, $to);
        $byYear = [];
        foreach ($daily as $day => $kwh) {
            $year = substr($day, 0, 4);
            $byYear[$year] = round(($byYear[$year] ?? 0.0) + $kwh, 4);
        }
        foreach ($byYear as $y => $v) {
            $byYear[$y] = round($v, 2);
        }
        return $byYear;
    }

    /**
     * Read from the shared JSON file cache.
     *
     * Cache files are stored in the system temp dir with the prefix "sparkspend_".
     * Returns the cached string when the file exists and is younger than $ttl seconds;
     * otherwise returns null.
     *
     * @param string $key  Cache identifier (e.g. 'dashboard_2025-07-12').  Must not
     *                     contain path separators.
     * @param int    $ttl  Maximum age in seconds.
     * @return string|null Cached JSON string, or null on cache miss.
     */
    public static function fileCacheRead(string $key, int $ttl): ?string {
        $dir  = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
        $file = $dir . DIRECTORY_SEPARATOR . 'sparkspend_' . $key . '.json';
        if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
            $content = file_get_contents($file);
            return $content !== false ? $content : null;
        }
        return null;
    }

    /**
     * Write a JSON string to the shared file cache.
     *
     * Failures are silently suppressed (temp dir may be read-only in some envs).
     *
     * @param string $key  Cache identifier — same key used in fileCacheRead().
     * @param string $json The JSON string to cache.
     */
    public static function fileCacheWrite(string $key, string $json): void {
        $dir  = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
        $file = $dir . DIRECTORY_SEPARATOR . 'sparkspend_' . $key . '.json';
        $handle = @fopen($file, 'c');
        if ($handle === false) return;
        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    /**
     * Delete all cache files whose key starts with the given prefix.
     *
     * Call this from mutation endpoints (create/update/delete) to ensure
     * dependent read endpoints don't serve stale data.
     *
     * @param string $prefix The key prefix to match (e.g. 'dashboard_').
     */
    public static function fileCacheInvalidatePattern(string $prefix): void {
        $dir     = $GLOBALS['cacheDir'] ?? sys_get_temp_dir();
        $pattern = $dir . DIRECTORY_SEPARATOR . 'sparkspend_' . $prefix . '*.json';
        foreach (glob($pattern) ?: [] as $file) {
            @unlink($file);
        }
    }
}
?>
