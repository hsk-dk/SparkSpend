<?php
/**
 * Query Builder for SparkSpend
 *
 * Provides reusable query methods with prepared statements to:
 * - Reduce code duplication
 * - Enable consistent prepared statement usage
 * - Centralize SQL injection prevention
 * - Standardize query patterns
 */

class QueryBuilder {

    /**
     * Parse date range string (handles both Danish and English formats)
     *
     * @param string $dateRange Date range string like "2024-01-01 til 2024-12-31"
     * @return array Array with 'start' and 'end' keys containing date strings
     * @throws Exception If date range format is invalid
     */
    public static function parseDateRange(string $dateRange): array {
        $dateRange = trim($dateRange);
        $dates = null;

        // Handle Danish format "YYYY-MM-DD til YYYY-MM-DD" (no space before 'til')
        if (strpos($dateRange, 'til ') !== false) {
            $dates = explode('til ', $dateRange);
        }
        // Handle English format "YYYY-MM-DD to YYYY-MM-DD"
        elseif (strpos($dateRange, ' to ') !== false) {
            $dates = explode(' to ', $dateRange);
        }

        if ($dates === null) {
            throw new Exception("Invalid date range format: {$dateRange}");
        }

        // Single date case (after split, only 1 element or 2 identical)
        if (count($dates) == 1) {
            return [
                'start' => trim($dates[0]),
                'end' => trim($dates[0]),
                'isSingleDate' => true
            ];
        }
        // Date range case
        elseif (count($dates) == 2) {
            return [
                'start' => trim($dates[0]),
                'end' => trim($dates[1]),
                'isSingleDate' => false
            ];
        }
        else {
            throw new Exception("Invalid date range format: {$dateRange}");
        }
    }

    /**
     * Get all vehicles from database
     *
     * @param PDO $db Database connection
     * @return array Array of vehicle records
     */
    public static function selectAllVehicles(PDO $db): array {
        try {
            $stmt = $db->prepare("SELECT * FROM vehicles ORDER BY vehicleName");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
        } catch (PDOException $e) {
            error_log("Error fetching vehicles: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get vehicle by ID
     *
     * @param PDO $db Database connection
     * @param int $id Vehicle ID
     * @return array|null Vehicle record or null if not found
     */
    public static function selectVehicleById(PDO $db, int $id): ?array {
        try {
            $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error fetching vehicle: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all charging providers
     *
     * @param PDO $db Database connection
     * @return array Array of provider records
     */
    public static function selectAllProviders(PDO $db): array {
        try {
            $stmt = $db->prepare("SELECT * FROM provideres ORDER BY providerName");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
        } catch (PDOException $e) {
            error_log("Error fetching providers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get provider by ID
     *
     * @param PDO $db Database connection
     * @param int $id Provider ID
     * @return array|null Provider record or null if not found
     */
    public static function selectProviderById(PDO $db, int $id): ?array {
        try {
            $stmt = $db->prepare("SELECT * FROM provideres WHERE id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error fetching provider: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get charges with optional filtering
     *
     * @param PDO $db Database connection
     * @param array $filters Filter options: vehicleId, dateRange, showZeroKwh
     * @return array Array of charge records
     */
    public static function selectCharges(PDO $db, array $filters = []): array {
        try {
            $query = "SELECT * FROM charges WHERE 1=1";
            $params = [];

            // Filter by vehicle
            if (!empty($filters['vehicleId'])) {
                $query .= " AND vehicleId = ?";
                $params[] = intval($filters['vehicleId']);
            }

            // Filter by date range
            if (!empty($filters['dateRange'])) {
                try {
                    $dates = self::parseDateRange($filters['dateRange']);
                    $query .= " AND date(createdAt) >= ? AND date(createdAt) <= ?";
                    $params[] = $dates['start'];
                    $params[] = $dates['end'];
                } catch (Exception $e) {
                    // Invalid date range, ignore filter
                    error_log("Invalid date range in selectCharges: " . $e->getMessage());
                }
            }

            // Filter zero kWh charges
            if (empty($filters['showZeroKwh'])) {
                $query .= " AND consumedKwh > 0";
            }

            // Order by date
            $query .= " ORDER BY createdAt DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
        } catch (PDOException $e) {
            error_log("Error fetching charges: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get vehicle charges with date filtering
     *
     * @param PDO $db Database connection
     * @param array $filters Filter options: vehicleId, dateRange
     * @return array Array of vehicle charge records
     */
    public static function selectVehicleCharges(PDO $db, array $filters = []): array {
        try {
            $query = "SELECT * FROM vehicle_charges WHERE 1=1";
            $params = [];

            // Filter by vehicle
            if (!empty($filters['vehicleId'])) {
                $query .= " AND vehicleId = ?";
                $params[] = intval($filters['vehicleId']);
            }

            // Filter by date range
            if (!empty($filters['dateRange'])) {
                try {
                    $dates = self::parseDateRange($filters['dateRange']);
                    $query .= " AND date(cablePluggedInAt) >= ? AND date(cablePluggedInAt) <= ?";
                    $params[] = $dates['start'];
                    $params[] = $dates['end'];
                } catch (Exception $e) {
                    // Invalid date range, ignore filter
                    error_log("Invalid date range in selectVehicleCharges: " . $e->getMessage());
                }
            }

            // Order by date
            $query .= " ORDER BY cablePluggedInAt DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
        } catch (PDOException $e) {
            error_log("Error fetching vehicle charges: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get external charges with optional filtering
     *
     * @param PDO $db Database connection
     * @param array $filters Filter options: vehicleId, dateRange
     * @return array Array of external charge records
     */
    public static function selectExternalCharges(PDO $db, array $filters = []): array {
        try {
            $query = "SELECT * FROM ext_charges WHERE 1=1";
            $params = [];

            // Filter by vehicle
            if (!empty($filters['vehicleId'])) {
                $query .= " AND vehicleId = ?";
                $params[] = intval($filters['vehicleId']);
            }

            // Filter by date range
            if (!empty($filters['dateRange'])) {
                try {
                    $dates = self::parseDateRange($filters['dateRange']);
                    $query .= " AND date(chargeDate) >= ? AND date(chargeDate) <= ?";
                    $params[] = $dates['start'];
                    $params[] = $dates['end'];
                } catch (Exception $e) {
                    error_log("Invalid date range in selectExternalCharges: " . $e->getMessage());
                }
            }

            // Order by date
            $query .= " ORDER BY chargeDate DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
        } catch (PDOException $e) {
            error_log("Error fetching external charges: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get heat pump power log data
     *
     * @param PDO $db Database connection (powerlog database)
     * @param string $period 'daily', 'monthly', or 'yearly'
     * @param string|null $dateFilter Optional date filter (YYYY-MM-DD or YYYY-MM or YYYY)
     * @return array Array of power log records
     */
    public static function selectPowerLogs(PDO $db, string $period = 'daily', ?string $dateFilter = null): array {
        try {
            $query = "SELECT * FROM powerlogjord WHERE 1=1";
            $params = [];

            // Apply date filter if provided
            if (!empty($dateFilter)) {
                if (strlen($dateFilter) === 4) {
                    // Year filter
                    $query .= " AND strftime('%Y', timestamp) = ?";
                    $params[] = $dateFilter;
                } elseif (strlen($dateFilter) === 7) {
                    // Month filter
                    $query .= " AND strftime('%Y-%m', timestamp) = ?";
                    $params[] = $dateFilter;
                } else {
                    // Day filter
                    $query .= " AND date(timestamp) = ?";
                    $params[] = $dateFilter;
                }
            }

            // Order by timestamp
            $query .= " ORDER BY timestamp DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
        } catch (PDOException $e) {
            error_log("Error fetching power logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create external charge record
     *
     * @param PDO $db Database connection
     * @param array $data Charge data: vehicleId, kwh, cost, chargeDate, provider, category
     * @return array Result array with 'success' and optional 'id' or 'error'
     */
    public static function insertExternalCharge(PDO $db, array $data): array {
        try {
            $stmt = $db->prepare(
                "INSERT INTO ext_charges (vehicleId, kwh, cost, chargeDate, provider, category)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            $result = $stmt->execute([
                intval($data['vehicleId'] ?? 0),
                floatval($data['kwh'] ?? 0),
                floatval($data['cost'] ?? 0),
                $data['chargeDate'] ?? date('Y-m-d H:i:s'),
                $data['provider'] ?? '',
                $data['category'] ?? ''
            ]);

            return [
                'success' => $result,
                'id' => $db->lastInsertId()
            ];
        } catch (PDOException $e) {
            error_log("Error inserting external charge: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update charge category
     *
     * @param PDO $db Database connection
     * @param int $chargeId Charge ID
     * @param int $vehicleId Vehicle ID
     * @return bool True if update successful
     */
    public static function updateChargeCategory(PDO $db, int $chargeId, int $vehicleId): bool {
        try {
            $stmt = $db->prepare(
                "UPDATE charges SET vehicleId = ? WHERE id = ?"
            );
            return $stmt->execute([$vehicleId, $chargeId]);
        } catch (PDOException $e) {
            error_log("Error updating charge category: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update internal charge
     *
     * @param PDO $db Database connection
     * @param array $data Charge update data: id, vehicleId, consumedKwh, cost
     * @return bool True if update successful
     */
    public static function updateInternalCharge(PDO $db, array $data): bool {
        try {
            $stmt = $db->prepare(
                "UPDATE charges SET vehicleId = ?, consumedKwh = ?, cost = ? WHERE id = ?"
            );
            return $stmt->execute([
                intval($data['vehicleId'] ?? 0),
                floatval($data['consumedKwh'] ?? 0),
                floatval($data['cost'] ?? 0),
                $data['id'] ?? 0
            ]);
        } catch (PDOException $e) {
            error_log("Error updating internal charge: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update external charge
     *
     * @param PDO $db Database connection
     * @param array $data Charge update data: id, vehicleId, kwh, cost, chargeDate, provider, category
     * @return bool True if update successful
     */
    public static function updateExternalCharge(PDO $db, array $data): bool {
        try {
            $stmt = $db->prepare(
                "UPDATE ext_charges SET vehicleId = ?, kwh = ?, cost = ?, chargeDate = ?, provider = ?, category = ? WHERE id = ?"
            );
            return $stmt->execute([
                intval($data['vehicleId'] ?? 0),
                floatval($data['kwh'] ?? 0),
                floatval($data['cost'] ?? 0),
                $data['chargeDate'] ?? date('Y-m-d H:i:s'),
                $data['provider'] ?? '',
                $data['category'] ?? '',
                intval($data['id'] ?? 0)
            ]);
        } catch (PDOException $e) {
            error_log("Error updating external charge: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete external charge
     *
     * @param PDO $db Database connection
     * @param int $chargeId Charge ID
     * @return bool True if delete successful
     */
    public static function deleteExternalCharge(PDO $db, int $chargeId): bool {
        try {
            $stmt = $db->prepare("DELETE FROM ext_charges WHERE id = ?");
            return $stmt->execute([intval($chargeId)]);
        } catch (PDOException $e) {
            error_log("Error deleting external charge: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record vehicle data (odometer, etc.)
     *
     * @param PDO $db Database connection
     * @param array $data Vehicle data: vehicleId, odometer, timestamp
     * @return bool True if insert successful
     */
    public static function insertVehicleData(PDO $db, array $data): bool {
        try {
            $stmt = $db->prepare(
                "INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer)
                 VALUES (?, ?, ?)"
            );
            return $stmt->execute([
                intval($data['vehicleId'] ?? 0),
                $data['timestamp'] ?? date('Y-m-d H:i:s'),
                intval($data['odometer'] ?? 0)
            ]);
        } catch (PDOException $e) {
            error_log("Error inserting vehicle data: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cost trend data grouped by time period
     *
     * @param PDO $db Database connection
     * @param array $filters Filter options: vehicleId, dateRange, groupBy (day/week/month)
     * @return array Array of cost trend records with aggregated data
     */
    public static function getCostTrend(PDO $db, array $filters = []): array {
        try {
            // Get internal charges - these have proper datetime format
            $queryInternal = "SELECT date(createdAt) as date, 'internal' as source, consumedKwh as kwh, cost as pris, vehicleId FROM charges WHERE 1=1";
            $paramsInternal = [];

            if (!empty($filters['vehicleId'])) {
                $queryInternal .= " AND vehicleId = ?";
                $paramsInternal[] = intval($filters['vehicleId']);
            }

            $stmtInternal = $db->prepare($queryInternal);
            $stmtInternal->execute($paramsInternal);
            $chargesInternal = $stmtInternal->fetchAll(PDO::FETCH_ASSOC) ?? [];

            // Get external charges - fetch all and filter in PHP due to ISO 8601 date format
            $queryExternal = "SELECT datetime, 'external' as source, kwh, pris, vehicleId FROM ext_charges WHERE 1=1";
            $paramsExternal = [];

            if (!empty($filters['vehicleId'])) {
                $queryExternal .= " AND vehicleId = ?";
                $paramsExternal[] = intval($filters['vehicleId']);
            }

            $stmtExternal = $db->prepare($queryExternal);
            $stmtExternal->execute($paramsExternal);
            $chargesExternal = $stmtExternal->fetchAll(PDO::FETCH_ASSOC) ?? [];

            // Filter external charges by date in PHP and convert datetime to date
            if (!empty($filters['dateRange'])) {
                try {
                    $dates = self::parseDateRange($filters['dateRange']);
                    $startTime = strtotime($dates['start']);
                    $endTime = strtotime($dates['end'] . ' 23:59:59');

                    $chargesExternal = array_filter($chargesExternal, function($charge) use ($startTime, $endTime) {
                        $chargeTime = strtotime($charge['datetime']);
                        return $chargeTime >= $startTime && $chargeTime <= $endTime;
                    });

                    // Convert datetime to date string
                    foreach ($chargesExternal as &$charge) {
                        $charge['date'] = date('Y-m-d', strtotime($charge['datetime']));
                    }
                    unset($charge);
                } catch (Exception $e) {
                    error_log("Invalid date range in getCostTrend: " . $e->getMessage());
                }
            } else {
                // Convert datetime to date string
                foreach ($chargesExternal as &$charge) {
                    $charge['date'] = date('Y-m-d', strtotime($charge['datetime']));
                }
                unset($charge);
            }

            // Also filter internal charges by date
            if (!empty($filters['dateRange'])) {
                try {
                    $dates = self::parseDateRange($filters['dateRange']);
                    $chargesInternal = array_filter($chargesInternal, function($charge) use ($dates) {
                        return $charge['date'] >= $dates['start'] && $charge['date'] <= $dates['end'];
                    });
                } catch (Exception $e) {
                    // Already logged
                }
            }

            // Combine and aggregate by date and source
            $allCharges = array_merge($chargesInternal, $chargesExternal);
            $aggregated = [];

            foreach ($allCharges as $charge) {
                $key = $charge['date'] . '_' . $charge['source'];
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'date' => $charge['date'],
                        'source' => $charge['source'],
                        'total_kwh' => 0,
                        'total_cost' => 0,
                        'charge_count' => 0
                    ];
                }
                $aggregated[$key]['total_kwh'] += floatval($charge['kwh']);
                $aggregated[$key]['total_cost'] += floatval($charge['pris']);
                $aggregated[$key]['charge_count']++;
            }

            // Return as array of records
            $result = array_values($aggregated);

            // Sort by date descending
            usort($result, function($a, $b) {
                return strcmp($b['date'], $a['date']);
            });

            return $result;
        } catch (PDOException $e) {
            error_log("Error fetching cost trend: " . $e->getMessage());
            return [];
        }
    }


    /**
     * Get cost statistics for selected period
     *
     * @param PDO $db Database connection
     * @param array $filters Filter options: vehicleId, dateRange
     * @return array Array with min/max/avg cost statistics
     */
    public static function getCostStatistics(PDO $db, array $filters = []): array {
        try {
            $query = "
                SELECT
                    MIN(cost) as min_cost,
                    MAX(cost) as max_cost,
                    AVG(cost) as avg_cost,
                    SUM(cost) as total_cost,
                    COUNT(*) as charge_count,
                    SUM(consumedKwh) as total_kwh,
                    CASE WHEN SUM(consumedKwh) > 0 THEN SUM(cost) / SUM(consumedKwh) ELSE 0 END as avg_cost_per_kwh
                FROM charges
                WHERE 1=1
            ";

            $params = [];

            if (!empty($filters['vehicleId'])) {
                $query .= " AND vehicleId = ?";
                $params[] = intval($filters['vehicleId']);
            }

            if (!empty($filters['dateRange'])) {
                try {
                    $dates = self::parseDateRange($filters['dateRange']);
                    $query .= " AND date(createdAt) >= ? AND date(createdAt) <= ?";
                    $params[] = $dates['start'];
                    $params[] = $dates['end'];
                } catch (Exception $e) {
                    error_log("Invalid date range in getCostStatistics: " . $e->getMessage());
                }
            }

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ?: [
                'min_cost' => 0,
                'max_cost' => 0,
                'avg_cost' => 0,
                'total_cost' => 0,
                'charge_count' => 0,
                'total_kwh' => 0,
                'avg_cost_per_kwh' => 0
            ];
        } catch (PDOException $e) {
            error_log("Error fetching cost statistics: " . $e->getMessage());
            return [];
        }
    }
}
?>
