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

    $stmt = $db->prepare("SELECT vehicleId FROM vehicle_charges
                          WHERE ABS(strftime('%s', cablePluggedInAt) - strftime('%s', ?)) < 3600
                          ORDER BY ABS(strftime('%s', cablePluggedInAt) - strftime('%s', ?))
                          LIMIT 1");

    $stmt->execute([$cablePluggedInAt, $cablePluggedInAt]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['vehicleId'] : 1;
}

// Funktion til at gemme data i databasen
function saveChargingData($db, $data) {

    $stmt = $db->prepare("INSERT OR REPLACE INTO charges
        (id, chargePointId, createdAt, updatedAt, cablePluggedInAt, startedAt, stoppedAt, state,
        consumedKwh, kwhLimit, startMeterKwh, endMeterKwh, cost, stopReason, socPercentage, socLimit, vehicleId)
        VALUES
        (:id, :chargePointId, :createdAt, :updatedAt, :cablePluggedInAt, :startedAt, :stoppedAt, :state,
        :consumedKwh, :kwhLimit, :startMeterKwh, :endMeterKwh, :cost, :stopReason, :socPercentage, :socLimit, :vehicleId)");

    foreach ($data['data'] as $charge) {
        // Find den rigtige bil baseret på kabeltilslutningstidspunktet
        $vehicleId = getVehicleForCharge($charge['cablePluggedInAt']);

        // Extract soc percentage with error checking
        $socPercentage = null;
        if (isset($charge['soc']) && is_array($charge['soc']) && isset($charge['soc']['percentage'])) {
            $socPercentage = $charge['soc']['percentage'];
        }

        $stmt->bindValue(':id', $charge['id']);
        $stmt->bindValue(':chargePointId', $charge['chargePointId']);
        $stmt->bindValue(':createdAt', $charge['createdAt']);
        $stmt->bindValue(':updatedAt', $charge['updatedAt']);
        $stmt->bindValue(':cablePluggedInAt', $charge['cablePluggedInAt']);
        $stmt->bindValue(':startedAt', $charge['startedAt']);
        $stmt->bindValue(':stoppedAt', $charge['stoppedAt']);
        $stmt->bindValue(':state', $charge['state']);
        $stmt->bindValue(':consumedKwh', $charge['consumedKwh']);
        $stmt->bindValue(':kwhLimit', $charge['kwhLimit']);
        $stmt->bindValue(':startMeterKwh', $charge['startMeterKwh']);
        $stmt->bindValue(':endMeterKwh', $charge['endMeterKwh']);
        $stmt->bindValue(':cost', $charge['cost']);
        $stmt->bindValue(':stopReason', $charge['stopReason']);
        $stmt->bindValue(':socPercentage', $socPercentage);
        $stmt->bindValue(':socLimit', $charge['socLimit']);
        $stmt->bindValue(':vehicleId', $vehicleId);

        $stmt->execute();
    }

//    echo "Data gemt i databasen med bilkategorisering!\n";
}

// Hent adgangstoken
$accessToken = getAccessToken($clientId, $clientSecret, $authEndpoint);

// Hent data fra Monta API med den udregnede fromDate og toDate
$data = getChargingData($accessToken, $dataEndpoint, $newFromDate, $toDate);

// Gem data i databasen
saveChargingData($db, $data);

//echo "Data hentet og gemt i databasen.";
?>
