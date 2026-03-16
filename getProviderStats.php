<?php
/**
 * Get external charge statistics grouped by provider
 *
 * PHP-side date and vehicle filtering required because ext_charges.datetime
 * is stored as ISO 8601 with Z suffix (e.g. 2024-12-07T15:37:00Z), which
 * SQLite strftime() cannot parse reliably.
 *
 * GET parameters:
 * - filter: vehicleId or 'all' (optional, default: 'all')
 * - dateRange: Date range in format "YYYY-MM-DD til YYYY-MM-DD" (optional)
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';

header('Content-Type: application/json');

try {
    $db = DatabaseManager::getChargesDb();

    $vehicleId = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $dateRange = isset($_GET['dateRange']) ? trim($_GET['dateRange']) : '';

    // Parse date range
    $startTs = null;
    $endTs   = null;
    if (!empty($dateRange)) {
        $parts = preg_split('/\s+til\s+/', $dateRange);
        if (count($parts) === 2) {
            $startTs = strtotime(trim($parts[0]));
            $endTs   = strtotime(trim($parts[1]) . ' 23:59:59');
        }
    }

    // Fetch all ext_charges with provider names.
    // PHP-side filtering handles the Z-suffix ISO 8601 datetime format.
    $stmt = $db->prepare("
        SELECT e.vehicleId, e.datetime, e.kwh, e.pris,
               COALESCE(p.providerName, 'Ukendt') AS providerName
        FROM ext_charges e
        LEFT JOIN providers p ON e.providerId = p.id
        WHERE e.kwh > 0
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $t = strtotime($row['datetime']);

        // Apply date filter
        if ($startTs !== null && ($t < $startTs || $t > $endTs)) {
            continue;
        }

        // Apply vehicle filter
        if ($vehicleId !== 'all' && intval($row['vehicleId']) !== intval($vehicleId)) {
            continue;
        }

        $name = $row['providerName'];
        if (!isset($map[$name])) {
            $map[$name] = [
                'providerName' => $name,
                'charge_count' => 0,
                'total_kwh'    => 0.0,
                'total_cost'   => 0.0,
            ];
        }
        $map[$name]['charge_count']++;
        $map[$name]['total_kwh']  += floatval($row['kwh']);
        $map[$name]['total_cost'] += floatval($row['pris']);
    }

    $result = [];
    foreach ($map as $entry) {
        $result[] = [
            'providerName'     => $entry['providerName'],
            'charge_count'     => $entry['charge_count'],
            'total_kwh'        => round($entry['total_kwh'], 2),
            'total_cost'       => round($entry['total_cost'], 2),
            'avg_cost_per_kwh' => $entry['total_kwh'] > 0
                ? round($entry['total_cost'] / $entry['total_kwh'], 3)
                : 0,
        ];
    }

    // Sort by avg_cost_per_kwh ascending (cheapest first)
    usort($result, fn($a, $b) => $a['avg_cost_per_kwh'] <=> $b['avg_cost_per_kwh']);

    http_response_code(200);
    echo json_encode($result);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in getProviderStats.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in getProviderStats.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
