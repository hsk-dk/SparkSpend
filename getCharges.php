<?php
/**
 * Get all charges (internal and external) with optional filtering
 *
 * GET parameters:
 * - filter: Vehicle ID filter (or 'all' for all vehicles)
 * - showZeroKwh: true to include zero kWh charges
 * - dateRange: Date range in format "YYYY-MM-DD til YYYY-MM-DD" or "YYYY-MM-DD to YYYY-MM-DD"
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

try {
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
        // Add params for each WHERE clause
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
        } catch (Exception $e) {
            // Invalid date range, ignore filter
            error_log("Invalid date range: " . $e->getMessage());
        }
    }

    if ($dateFilters) {
        $internalWhere[] = "date(createdAt) BETWEEN ? AND ?";
        $externalWhere[] = "date(datetime) BETWEEN ? AND ?";
    }

    // Build WHERE clauses
    $internalWhereStr = count($internalWhere) > 0 ? "WHERE " . implode(" AND ", $internalWhere) : "";
    $externalWhereStr = count($externalWhere) > 0 ? "WHERE " . implode(" AND ", $externalWhere) : "";

    // Build final query with UNION ALL
    $query = "
        SELECT
            id,
            strftime('%Y-%m-%dT%H:%M:%S', createdAt) AS datetime,
            consumedKwh AS kwh,
            cost AS pris,
            vehicleId,
            NULL AS providerId,
            'internal' AS source
        FROM charges
        $internalWhereStr
        UNION ALL
        SELECT
            id,
            strftime('%Y-%m-%dT%H:%M:%S', datetime) AS datetime,
            kwh,
            pris,
            vehicleId,
            providerId,
            'external' AS source
        FROM ext_charges
        $externalWhereStr
        ORDER BY datetime ASC
    ";

    // Prepare and execute
    $stmt = $db->prepare($query);

    // Build parameter array for prepared statement
    // IMPORTANT: Parameters must match the placeholder order in the UNION query
    // Order: internal filters, then external filters (in same order)
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
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in getCharges.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in getCharges.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
