<?php
/**
 * Get all charges (internal and external) with optional filtering
 *
 * GET parameters:
 * - filter: Vehicle ID filter (or 'all' for all vehicles)
 * - showZeroKwh: true to include zero kWh charges
 * - dateRange: Date range in format "YYYY-MM-DD til YYYY-MM-DD" or "YYYY-MM-DD to YYYY-MM-DD"
 */

$db = DatabaseManager::getChargesDb();

// Parse request parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$showZeroKwh = (isset($_GET['showZeroKwh']) && $_GET['showZeroKwh'] === 'true') ? true : false;
$dateRange = isset($_GET['dateRange']) ? $_GET['dateRange'] : '';

// Build dynamic query with prepared statements
$internalWhere = [];
$externalWhere = [];
$params = [];

// Vehicle filter
if ($filter !== 'all') {
    $vehicleId = intval($filter);
    $internalWhere[] = "vehicleId = ?";
    $externalWhere[] = "vehicleId = ?";
}

// kWh filter
if (!$showZeroKwh) {
    $internalWhere[] = "consumedKwh > 0";
    $externalWhere[] = "kwh > 0";
}

// Date range filter
$dateFilters = null;
if ($dateRange) {
    try {
        $dateFilters = QueryBuilder::parseDateRange($dateRange);
    } catch (\Throwable $e) {
        // Invalid date range, ignore filter
        error_log("Invalid date range: " . $e->getMessage());
    }
}

if ($dateFilters) {
    $internalWhere[] = "DATE(stoppedAt) BETWEEN ? AND ?";
    $externalWhere[] = "DATE(datetime) BETWEEN ? AND ?";
}

// Build WHERE clauses
$internalWhereStr = count($internalWhere) > 0 ? "WHERE " . implode(" AND ", $internalWhere) : "";
$externalWhereStr = count($externalWhere) > 0 ? "WHERE " . implode(" AND ", $externalWhere) : "";

// Build final query with UNION ALL
$query = "
    SELECT
        id,
        stoppedAt AS datetime,
        consumedKwh AS kwh,
        cost AS pris,
        vehicleId,
        NULL AS providerId,
        'internal' AS source,
        startedAt,
        stoppedAt,
        state,
        stopReason,
        socPercentage,
        socLimit,
        pairingSource
    FROM charges
    $internalWhereStr
    UNION ALL
    SELECT
        id,
        datetime,
        kwh,
        pris,
        vehicleId,
        providerId,
        'external' AS source,
        NULL AS startedAt,
        NULL AS stoppedAt,
        NULL AS state,
        NULL AS stopReason,
        NULL AS socPercentage,
        NULL AS socLimit,
        NULL AS pairingSource
    FROM ext_charges
    $externalWhereStr
    ORDER BY datetime ASC
";

// Prepare and execute
$stmt = $db->prepare($query);

// Build parameter array for prepared statement
$stmtParams = [];

// First: internal query parameters (vehicleId, then dates)
if ($filter !== 'all') {
    $vehicleId = intval($filter);
    $stmtParams[] = $vehicleId;
}

if ($dateFilters) {
    $stmtParams[] = $dateFilters['start'];
    $stmtParams[] = $dateFilters['end'];
}

// Second: external query parameters (vehicleId, then dates) - same as internal
if ($filter !== 'all') {
    $vehicleId = intval($filter);
    $stmtParams[] = $vehicleId;
}

if ($dateFilters) {
    $stmtParams[] = $dateFilters['start'];
    $stmtParams[] = $dateFilters['end'];
}

$stmt->execute($stmtParams);
$charges = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

// Return JSON response
echo json_encode($charges);
