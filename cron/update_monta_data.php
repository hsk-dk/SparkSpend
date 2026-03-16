<?php
require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';
require __DIR__ . '/../includes/QueryBuilder.php';

try {
    $db = DatabaseManager::getChargesDb();
} catch (PDOException $e) {
    die("Databaseforbindelse fejlede: " . $e->getMessage());
}

// Udregn den nye fromDate baseret på den nyeste stoppedAt-værdi i databasen
$defaultFromDate = "2022-05-22T09:30:03Z"; // Standardværdi hvis ingen data findes
$stmt = $db->query("SELECT MAX(stoppedAt) AS maxStoppedAt FROM charges");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row && !empty($row['maxStoppedAt'])) {
    $maxStoppedAt = $row['maxStoppedAt'];
    $timestamp = strtotime($maxStoppedAt);
    if ($timestamp !== false) {
        // Læg 1 sekund til den nyeste stoppedAt-værdi
        $newFromDate = date('Y-m-d\TH:i:s\Z', $timestamp + 1);
    } else {
        $newFromDate = $defaultFromDate;
    }
} else {
    $newFromDate = $defaultFromDate;
}

// Udregn toDate som nuværende tid + 1 time
$toDate = date('Y-m-d\TH:i:s\Z', strtotime('+1 hour'));

// Funktion til at hente adgangstoken
function getAccessToken($clientId, $clientSecret, $authEndpoint) {
    $data = array('clientId' => $clientId, 'clientSecret' => $clientSecret);
    $ch = curl_init($authEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json','Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        die("Fejl ved hentning af adgangstoken: HTTP statuskode " . $httpCode);
    }

    $data = json_decode($response, true);
    if (isset($data["accessToken"])) {
        return $data["accessToken"];
    } else {
        die("Fejl ved hentning af adgangstoken: " . json_encode($data));
    }
}

// Funktion til at hente data fra Monta API
function getChargingData($accessToken, $dataEndpoint, $fromDate, $toDate) {
    //echo "Henter data fra API...\n";
    $queryParams = http_build_query([
        'fromDate' => $fromDate,
        'toDate'   => $toDate,
        'page'     => 0,
        'perPage'  => 100
    ]);
    $ch = curl_init($dataEndpoint . "?" . $queryParams);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $accessToken
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        die("Fejl ved hentning af data: HTTP statuskode " . $httpCode);
    }

    return json_decode($response, true);
}

function getVehicleForCharge($cablePluggedInAt) {
    $db = DatabaseManager::getChargesDb();

    // PHP's strtotime() handles both "+01:00" (old rows) and "Z" (new rows) correctly,
    // unlike SQLite's strftime('%s', ...) which returns NULL for "+HH:MM" offsets on
    // many SQLite versions, causing every charge to fall back to vehicleId=1.
    $targetTime = strtotime($cablePluggedInAt);
    if ($targetTime === false) {
        error_log("getVehicleForCharge: could not parse Monta timestamp: $cablePluggedInAt");
        return 1;
    }

    // Fetch the 500 most recent vehicle_charges rows and compare in PHP
    $stmt = $db->query("SELECT vehicleId, cablePluggedInAt FROM vehicle_charges
                        ORDER BY rowid DESC LIMIT 500");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bestVehicleId = 1;
    $bestDiff      = PHP_INT_MAX;
    foreach ($rows as $row) {
        $storedTime = strtotime($row['cablePluggedInAt']);
        if ($storedTime === false) continue;
        $diff = abs($targetTime - $storedTime);
        if ($diff < $bestDiff) {
            $bestDiff      = $diff;
            $bestVehicleId = $row['vehicleId'];
        }
    }

    if ($bestDiff >= 3600) {
        error_log("getVehicleForCharge: no vehicle_charges row within 1 hour of $cablePluggedInAt (closest diff: {$bestDiff}s) — defaulting to vehicleId=1");
        return 1;
    }

    return $bestVehicleId;
}

// Funktion til at gemme data i databasen
function saveChargingData($db, $data) {
    foreach ($data['data'] as $charge) {
        // Find den rigtige bil baseret på kabeltilslutningstidspunktet
        $vehicleId = getVehicleForCharge($charge['cablePluggedInAt']);

        // Keep vehicles table current. Extract name from API response if available
        // (Monta may return vehicle info in future API versions or under different keys).
        $vehicleName = trim(
            $charge['vehicleName']
            ?? $charge['vehicle']['name']
            ?? $charge['vehicle']['displayName']
            ?? ''
        );
        QueryBuilder::upsertVehicle($db, $vehicleId, $vehicleName);

        // Extract soc percentage with error checking
        $socPercentage = null;
        if (isset($charge['soc']) && is_array($charge['soc']) && isset($charge['soc']['percentage'])) {
            $socPercentage = $charge['soc']['percentage'];
        }

        // Check if record already exists
        $checkStmt = $db->prepare("SELECT vehicleId FROM charges WHERE id = ?");
        $checkStmt->execute([$charge['id']]);
        $existingCharge = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingCharge) {
            // Record exists: UPDATE only API fields, preserve vehicleId if user manually changed it
            // Only update vehicleId if it's still the default (0 or not set)
            $existingVehicleId = $existingCharge['vehicleId'];
            $vehicleIdToUse = ($existingVehicleId == 0 || $existingVehicleId == null) ? $vehicleId : $existingVehicleId;

            $updateStmt = $db->prepare("UPDATE charges SET
                chargePointId = ?,
                updatedAt = ?,
                startedAt = ?,
                stoppedAt = ?,
                state = ?,
                consumedKwh = ?,
                kwhLimit = ?,
                startMeterKwh = ?,
                endMeterKwh = ?,
                cost = ?,
                stopReason = ?,
                socPercentage = ?,
                socLimit = ?,
                vehicleId = ?
                WHERE id = ?");

            $updateStmt->execute([
                $charge['chargePointId'],
                $charge['updatedAt'],
                $charge['startedAt'],
                $charge['stoppedAt'],
                $charge['state'],
                $charge['consumedKwh'],
                $charge['kwhLimit'],
                $charge['startMeterKwh'],
                $charge['endMeterKwh'],
                $charge['cost'],
                $charge['stopReason'],
                $socPercentage,
                $charge['socLimit'],
                $vehicleIdToUse,
                $charge['id']
            ]);
        } else {
            // Record doesn't exist: INSERT new record with auto-detected vehicleId
            $insertStmt = $db->prepare("INSERT INTO charges
                (id, chargePointId, createdAt, updatedAt, cablePluggedInAt, startedAt, stoppedAt, state,
                consumedKwh, kwhLimit, startMeterKwh, endMeterKwh, cost, stopReason, socPercentage, socLimit, vehicleId)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $insertStmt->execute([
                $charge['id'],
                $charge['chargePointId'],
                $charge['createdAt'],
                $charge['updatedAt'],
                $charge['cablePluggedInAt'],
                $charge['startedAt'],
                $charge['stoppedAt'],
                $charge['state'],
                $charge['consumedKwh'],
                $charge['kwhLimit'],
                $charge['startMeterKwh'],
                $charge['endMeterKwh'],
                $charge['cost'],
                $charge['stopReason'],
                $socPercentage,
                $charge['socLimit'],
                $vehicleId
            ]);
        }
    }
}

// Hent adgangstoken
$accessToken = getAccessToken($clientId, $clientSecret, $authEndpoint);

// Hent data fra Monta API med den udregnede fromDate og toDate
$data = getChargingData($accessToken, $dataEndpoint, $newFromDate, $toDate);

// Gem data i databasen
saveChargingData($db, $data);

//echo "Data hentet og gemt i databasen.";
?>
