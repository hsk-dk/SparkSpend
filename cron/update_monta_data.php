<?php
require __DIR__ . '/../includes/configuration.php';
// Opret forbindelse til SQLite-database via PDO
 $dbPath = __DIR__ . '/../'.$dbPath;
try {
    if (!file_exists($dbPath)) {
        // Opret databasen, hvis den ikke findes
        echo "PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP";
		//$db = new PDO('sqlite:' . $dbPath);
        //$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        /*$db->exec("CREATE TABLE IF NOT EXISTS charges (
             id INTEGER PRIMARY KEY,
             chargePointId INTEGER,
             createdAt TEXT,
             updatedAt TEXT,
             cablePluggedInAt TEXT,
             startedAt TEXT,
             stoppedAt TEXT,
             state TEXT,
             consumedKwh REAL,
             kwhLimit INTEGER,
             startMeterKwh REAL,
             endMeterKwh REAL,
             cost REAL,
             stopReason TEXT,
             socPercentage INTEGER,
             socLimit INTEGER,
             vehicleId INTEGER
        )");*/
    } else {
        // Forbind til eksisterende database
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
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
	global $dbPath; 
	$db = new SQLite3($dbPath);

    $stmt = $db->prepare("SELECT vehicleId FROM vehicle_charges 
                          WHERE ABS(strftime('%s', cablePluggedInAt) - strftime('%s', :cablePluggedInAt)) < 3600
                          ORDER BY ABS(strftime('%s', cablePluggedInAt) - strftime('%s', :cablePluggedInAt))
                          LIMIT 1");

    $stmt->bindValue(':cablePluggedInAt', $cablePluggedInAt, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
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

        $stmt->bindValue(':id', $charge['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':chargePointId', $charge['chargePointId'], SQLITE3_INTEGER);
        $stmt->bindValue(':createdAt', $charge['createdAt'], SQLITE3_TEXT);
        $stmt->bindValue(':updatedAt', $charge['updatedAt'], SQLITE3_TEXT);
        $stmt->bindValue(':cablePluggedInAt', $charge['cablePluggedInAt'], SQLITE3_TEXT);
        $stmt->bindValue(':startedAt', $charge['startedAt'], SQLITE3_TEXT);
        $stmt->bindValue(':stoppedAt', $charge['stoppedAt'], SQLITE3_TEXT);
        $stmt->bindValue(':state', $charge['state'], SQLITE3_TEXT);
        $stmt->bindValue(':consumedKwh', $charge['consumedKwh'], SQLITE3_FLOAT);
        $stmt->bindValue(':kwhLimit', $charge['kwhLimit'], SQLITE3_INTEGER);
        $stmt->bindValue(':startMeterKwh', $charge['startMeterKwh'], SQLITE3_FLOAT);
        $stmt->bindValue(':endMeterKwh', $charge['endMeterKwh'], SQLITE3_FLOAT);
        $stmt->bindValue(':cost', $charge['cost'], SQLITE3_FLOAT);
        $stmt->bindValue(':stopReason', $charge['stopReason'], SQLITE3_TEXT);
        $stmt->bindValue(':socPercentage', $charge['soc']['percentage'], SQLITE3_INTEGER);
        $stmt->bindValue(':socLimit', $charge['socLimit'], SQLITE3_INTEGER);
        $stmt->bindValue(':vehicleId', $vehicleId, SQLITE3_INTEGER);

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
