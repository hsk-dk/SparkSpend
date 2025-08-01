<?php
header('Content-Type: application/json');
require 'includes/configuration.php';
date_default_timezone_set('Europe/Copenhagen');

try {
    $db = new PDO('sqlite:data/powerlog_data.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $mode = $_GET['mode'] ?? 'daily';
    $response = [];

    if ($mode === 'daily' && isset($_GET['month'])) {
        // Daglig forbrugsoversigt baseret på forskellen mellem første og sidste måling
        $month = $_GET['month']; // Format: YYYY-MM
        $stmt = $db->prepare("
            SELECT 
                DATE(logdate) AS day, 
                (MAX(kwh) - MIN(kwh)) AS total_kwh 
            FROM powerlogjord 
            WHERE strftime('%Y-%m', logdate) = :month 
            GROUP BY day 
            ORDER BY day;
        ");
        $stmt->execute(['month' => $month]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($mode === 'monthly' && isset($_GET['year'])) {
        // Månedlig forbrugsoversigt baseret på forskellen mellem første og sidste måling
        $year = $_GET['year']; // Format: YYYY
        $stmt = $db->prepare("
            SELECT 
                strftime('%Y-%m', logdate) AS month, 
                (MAX(kwh) - MIN(kwh)) AS total_kwh 
            FROM powerlogjord 
            WHERE strftime('%Y', logdate) = :year 
            GROUP BY month 
            ORDER BY month;
        ");
        $stmt->execute(['year' => $year]);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($mode === 'compare') {
        // Sammenligning af månedligt forbrug på tværs af år
        $stmt = $db->query("
            SELECT 
                strftime('%Y', logdate) AS year, 
                strftime('%m', logdate) AS month, 
                (MAX(kwh) - MIN(kwh)) AS total_kwh 
            FROM powerlogjord 
            GROUP BY year, month 
            ORDER BY year DESC, month;
        ");
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fejlhåndtering: Hvis der ikke findes data
    if (empty($response)) {
        echo json_encode(["error" => "Ingen data fundet."]);
        exit;
    }

    echo json_encode($response);
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}
