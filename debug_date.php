<?php
// Debug date filtering in getEfficiencyStats.php
$db = new SQLite3('data/charging_data.db');

// Test dateRange parameter
$dateRange = '2025-01-01 to 2025-01-31';
echo "Original dateRange: $dateRange\n";

// Parse dateRange
$startTimestamp = null;
$endTimestamp = null;
if (!empty($dateRange) && strpos($dateRange, ' to ') !== false) {
    $dates = explode(' to ', $dateRange);
    echo "Exploded dates: " . json_encode($dates) . "\n";
    if (count($dates) === 2) {
        $startTimestamp = strtotime(trim($dates[0]) . " 00:00:00");
        $endTimestamp = strtotime(trim($dates[1]) . " 23:59:59");
        echo "Start timestamp: $startTimestamp (" . date('Y-m-d H:i:s', $startTimestamp) . ")\n";
        echo "End timestamp: $endTimestamp (" . date('Y-m-d H:i:s', $endTimestamp) . ")\n";
    }
}

// Check vehicle_charges data format
echo "\nSample vehicle_charges data:\n";
$result = $db->query("SELECT cablePluggedInAt, strftime('%s', cablePluggedInAt) as timestamp FROM vehicle_charges LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    echo "Date: {$row['cablePluggedInAt']}, Timestamp: {$row['timestamp']}\n";
}

// Check all_charges data format  
echo "\nSample all_charges data:\n";
$result = $db->query("SELECT datetime, strftime('%s', datetime) as timestamp FROM all_charges LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    echo "Date: {$row['datetime']}, Timestamp: {$row['timestamp']}\n";
}

// Test WHERE clause
if ($startTimestamp && $endTimestamp) {
    echo "\nTesting WHERE clause:\n";
    $whereClause = "strftime('%s', cablePluggedInAt) BETWEEN $startTimestamp AND $endTimestamp";
    echo "WHERE clause: $whereClause\n";
    
    $query = "SELECT COUNT(*) as count FROM vehicle_charges WHERE $whereClause";
    $result = $db->query($query);
    $row = $result->fetchArray(SQLITE3_ASSOC);
    echo "Records matching date filter: {$row['count']}\n";
}

$db->close();
?>
