<?php
require __DIR__ . '/../includes/configuration.php';
require __DIR__ . '/../includes/DatabaseManager.php';
require __DIR__ . '/../includes/QueryBuilder.php';

/**
 * Append a timestamped line to cron/cron.log.
 */
function logMsg(string $message, string $level = 'INFO'): void {
    $logFile = __DIR__ . '/cron.log';
    $line    = '[' . date('Y-m-d H:i:s') . "] [{$level}] Monta Sync: {$message}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    if ($level === 'ERROR') {
        error_log("Monta Sync: {$message}");
    }
}

try {
    $db = DatabaseManager::getChargesDb();
} catch (PDOException $e) {
    logMsg("Databaseforbindelse fejlede: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// Udregn den nye fromDate baseret på den nyeste stoppedAt-værdi i databasen.
// CAST(stoppedAt AS TEXT) sikrer korrekt sammenligning selv hvis gamle rækker er
// gemt som BLOB (ældre PHP/PDO-SQLite-adfærd — BLOB sorterer over TEXT i SQLite).
$defaultFromDate = "2022-05-22T09:30:03Z"; // Standardværdi hvis ingen data findes
$stmt = $db->query("SELECT MAX(CAST(stoppedAt AS TEXT)) AS maxStoppedAt FROM charges");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row && !empty($row['maxStoppedAt'])) {
    $maxStoppedAt = $row['maxStoppedAt'];
    $timestamp = strtotime($maxStoppedAt);
    if ($timestamp !== false) {
        // Læg 1 sekund til den nyeste stoppedAt-værdi
        $newFromDate = gmdate('Y-m-d\TH:i:s\Z', $timestamp + 1);
    } else {
        $newFromDate = $defaultFromDate;
    }
} else {
    $newFromDate = $defaultFromDate;
}

// Udregn toDate som nuværende tid + 1 time
$toDate = gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 hour'));

// Funktion til at hente adgangstoken
function getAccessToken($clientId, $clientSecret, $authEndpoint) {
    $data = array('clientId' => $clientId, 'clientSecret' => $clientSecret);
    $ch = curl_init($authEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json','Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    // Timeouts so a hanging Monta endpoint can't block the whole cron run.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        logMsg("Fejl ved hentning af adgangstoken: HTTP " . $httpCode, 'ERROR');
        exit(1);
    }

    $data = json_decode($response, true);
    if (isset($data["accessToken"])) {
        return $data["accessToken"];
    } else {
        logMsg("Adgangstoken mangler i svar: " . json_encode($data), 'ERROR');
        exit(1);
    }
}

// Funktion til at hente data fra Monta API (with pagination)
function getChargingData($accessToken, $dataEndpoint, $fromDate, $toDate) {
    $allCharges = [];
    $page       = 0;
    $perPage    = 100;

    while (true) {
        $queryParams = http_build_query([
            'fromDate' => $fromDate,
            'toDate'   => $toDate,
            'page'     => $page,
            'perPage'  => $perPage
        ]);
        // Fetch one page with timeouts + simple back-off on transient 5xx errors.
        $response = false;
        $httpCode = 0;
        $curlErr  = '';
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($dataEndpoint . "?" . $queryParams);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $accessToken
            ));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            // Retry on network failure or transient 5xx; break otherwise.
            $transient = ($response === false) || ($httpCode >= 500 && $httpCode <= 599);
            if (!$transient || $attempt === $maxAttempts) {
                break;
            }
            $backoff = 2 ** $attempt; // 2s, 4s
            logMsg("Transient fejl (HTTP {$httpCode}" . ($curlErr ? ", {$curlErr}" : '') . ") på side {$page} — forsøg {$attempt}/{$maxAttempts}, venter {$backoff}s.", 'WARN');
            sleep($backoff);
        }

        if ($response === false) {
            logMsg("Netværksfejl mod Monta API på side {$page}: {$curlErr} — afventer næste kørsel.", 'ERROR');
            return null;
        }

        if ($httpCode === 429) {
            logMsg("Monta API rate-limit (429) — afventer næste kørsel.", 'WARN');
            return null;
        }

        if ($httpCode !== 200) {
            logMsg("Fejl ved hentning af data: HTTP " . $httpCode . " (page {$page})", 'ERROR');
            exit(1);
        }

        $decoded = json_decode($response, true);
        $batch   = $decoded['data'] ?? [];
        $allCharges = array_merge($allCharges, $batch);

        // Last page: fewer results than requested
        if (count($batch) < $perPage) break;

        $page++;

        // Safety limit: max 20,000 charges per sync run
        if ($page > 200) {
            logMsg("Pagination safety limit reached (page {$page}) — will continue on next run.", 'WARN');
            break;
        }
    }

    if ($page > 0) {
        logMsg("Fetched " . count($allCharges) . " charges across " . ($page + 1) . " pages.");
    }

    return ['data' => $allCharges];
}

// Cache vehicle count for the lifetime of this script — avoids one DB query per charge.
$_vehicleCount = null;
function _getVehicleCount(): int {
    global $_vehicleCount;
    if ($_vehicleCount === null) {
        $db = DatabaseManager::getChargesDb();
        $_vehicleCount = (int) ($db->query("SELECT COUNT(*) FROM vehicles")->fetchColumn() ?: 1);
    }
    return $_vehicleCount;
}

// Cache the recent vehicle_charges rows (with pre-parsed timestamps) once for
// the whole sync run — previously this was queried and re-parsed for EVERY
// charge (O(n) DB round-trips). Returns array of ['vehicleId'=>int,'ts'=>int].
$_vehicleChargeWindow = null;
function _getVehicleChargeWindow(): array {
    global $_vehicleChargeWindow;
    if ($_vehicleChargeWindow === null) {
        $db = DatabaseManager::getChargesDb();
        $stmt = $db->query("SELECT vehicleId, cablePluggedInAt FROM vehicle_charges
                            ORDER BY rowid DESC LIMIT 1000");
        $_vehicleChargeWindow = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['cablePluggedInAt'] === null) continue;
            $ts = strtotime($row['cablePluggedInAt']);
            if ($ts === false) continue;
            $_vehicleChargeWindow[] = ['vehicleId' => (int) $row['vehicleId'], 'ts' => $ts];
        }
        // Ascending by time so "latest plug-in <= T" is a simple scan.
        usort($_vehicleChargeWindow, fn($a, $b) => $a['ts'] <=> $b['ts']);
    }
    return $_vehicleChargeWindow;
}

function getVehicleForCharge($cablePluggedInAt): array {
    // Physical flow: the cable is plugged into a car first (HA reports the
    // vehicle + odometer at that moment); Monta may then schedule the charge to
    // start hours later. So the charge belongs to the vehicle whose cable was
    // plugged in MOST RECENTLY at or before this charge's cablePluggedInAt.
    // We take the latest HA reading with ts <= T (+ small grace), bounded by a
    // max look-back. With two cars sharing one charger, timing is the only
    // signal, so genuinely ambiguous cases are flagged 'uncertain' for review.
    $targetTime = strtotime($cablePluggedInAt);
    if ($targetTime === false) {
        logMsg("getVehicleForCharge: could not parse timestamp: $cablePluggedInAt", 'WARN');
        return ['vehicleId' => 1, 'source' => 'uncertain'];
    }

    $graceAfter  = Config::pairingGraceAfterSec();
    $maxLookback = Config::pairingMaxLookbackSec();
    $ambiguity   = Config::pairingAmbiguitySec();
    $cutoff      = $targetTime + $graceAfter;

    // Readings are sorted ascending by time. Collect all at/before the cutoff;
    // the last one is the most recent plug-in — that's the chosen vehicle.
    $eligible = [];
    foreach (_getVehicleChargeWindow() as $row) {
        if ($row['ts'] > $cutoff) break;   // ascending order → done
        $eligible[] = $row;
    }

    $chosen = !empty($eligible) ? end($eligible) : null;

    // Ambiguity check: scan backwards from the chosen reading for the most
    // recent reading belonging to a DIFFERENT vehicle.
    $prevOtherVehicleTs = null;
    if ($chosen !== null) {
        for ($i = count($eligible) - 2; $i >= 0; $i--) {
            if ($eligible[$i]['vehicleId'] !== $chosen['vehicleId']) {
                $prevOtherVehicleTs = $eligible[$i]['ts'];
                break;
            }
        }
    }

    if ($chosen === null) {
        if (_getVehicleCount() > 1) {
            logMsg("getVehicleForCharge: no plug-in at/before $cablePluggedInAt — uncertain", 'WARN');
        }
        return ['vehicleId' => 1, 'source' => 'uncertain'];
    }

    // Too old to trust (e.g. a stale reading days before this charge).
    if (($targetTime - $chosen['ts']) > $maxLookback) {
        if (_getVehicleCount() > 1) {
            $ageH = round(($targetTime - $chosen['ts']) / 3600, 1);
            logMsg("getVehicleForCharge: latest plug-in is {$ageH}h before $cablePluggedInAt (> max look-back) — uncertain", 'WARN');
        }
        return ['vehicleId' => $chosen['vehicleId'], 'source' => 'uncertain'];
    }

    // Ambiguous if a different vehicle plugged in just before the chosen one
    // (both cars connected close together → can't reliably tell which charged).
    if ($prevOtherVehicleTs !== null && ($chosen['ts'] - $prevOtherVehicleTs) <= $ambiguity) {
        logMsg("getVehicleForCharge: ambiguous pairing for $cablePluggedInAt " .
               "(another vehicle plugged in within {$ambiguity}s before chosen vid={$chosen['vehicleId']}) — uncertain", 'WARN');
        return ['vehicleId' => $chosen['vehicleId'], 'source' => 'uncertain'];
    }

    return ['vehicleId' => $chosen['vehicleId'], 'source' => 'matched'];
}

/**
 * Normalize a Monta API timestamp to UTC Z-suffix format.
 * Monta returns offsets like +01:00 or +02:00 depending on CET/CEST.
 * We standardize everything to YYYY-MM-DDTHH:MM:SSZ for consistent
 * SQLite date function behaviour and simple string comparisons.
 */
function _toUtcZ(?string $ts): ?string {
    if ($ts === null || $ts === '') return $ts;
    if (str_ends_with($ts, 'Z')) return $ts; // already UTC
    $epoch = strtotime($ts);
    if ($epoch === false) return $ts; // unparseable — store as-is
    return gmdate('Y-m-d\TH:i:s\Z', $epoch);
}

// Funktion til at gemme data i databasen
function saveChargingData($db, $data) {
    $db->beginTransaction();
    try {
        foreach ($data['data'] as $charge) {
        // Normalize all timestamps to UTC Z-suffix before storing
        $charge['createdAt']       = _toUtcZ($charge['createdAt'] ?? null);
        $charge['updatedAt']       = _toUtcZ($charge['updatedAt'] ?? null);
        $charge['cablePluggedInAt']= _toUtcZ($charge['cablePluggedInAt'] ?? null);
        $charge['startedAt']       = _toUtcZ($charge['startedAt'] ?? null);
        $charge['stoppedAt']       = _toUtcZ($charge['stoppedAt'] ?? null);

        // Determine vehicleId: prioritize Monta API's field (Partner API only),
        // then HA time-match, then default. Public API does not include vehicleId.
        $montaVehicleId = isset($charge['vehicleId']) ? intval($charge['vehicleId']) : 0;
        $pairingSource  = 'default';

        if ($montaVehicleId > 0) {
            $vehicleId     = $montaVehicleId;
            $pairingSource = 'api';
        } else {
            $pairing       = getVehicleForCharge($charge['cablePluggedInAt']);
            $vehicleId     = $pairing['vehicleId'];
            $pairingSource = $pairing['source'];
        }

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
        $checkStmt = $db->prepare("SELECT vehicleId, pairingSource FROM charges WHERE id = ?");
        $checkStmt->execute([$charge['id']]);
        $existingCharge = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingCharge) {
            // Record exists: preserve vehicleId if user manually changed it
            $existingVehicleId = $existingCharge['vehicleId'];
            $existingSource    = $existingCharge['pairingSource'] ?? 'legacy';

            // Never overwrite a manual pairing; otherwise use the new determined value
            if ($existingSource === 'manual') {
                $vehicleIdToUse   = $existingVehicleId;
                $pairingSourceUse = 'manual';
            } elseif ($existingVehicleId == 0 || $existingVehicleId == null) {
                $vehicleIdToUse   = $vehicleId;
                $pairingSourceUse = $pairingSource;
            } else {
                $vehicleIdToUse   = $vehicleId;
                $pairingSourceUse = $pairingSource;
            }

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
                vehicleId = ?,
                pairingSource = ?
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
                $pairingSourceUse,
                $charge['id']
            ]);
        } else {
            // Record doesn't exist: INSERT new record
            $insertStmt = $db->prepare("INSERT INTO charges
                (id, chargePointId, createdAt, updatedAt, cablePluggedInAt, startedAt, stoppedAt, state,
                consumedKwh, kwhLimit, startMeterKwh, endMeterKwh, cost, stopReason, socPercentage, socLimit, vehicleId, pairingSource)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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
                $vehicleId,
                $pairingSource
            ]);
        }
    }
    $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── Sync execution ───────────────────────────────────────────────────────────

// Validate Monta credentials are configured
$clientId     = Config::montaClientId();
$clientSecret = Config::montaClientSecret();
$authEndpoint = Config::montaAuthEndpoint();
$dataEndpoint = Config::montaDataEndpoint();

if (empty($clientId) || empty($clientSecret)) {
    logMsg("MONTA_CLIENT_ID / MONTA_CLIENT_SECRET not configured in .env — skipping sync.", 'ERROR');
    exit(1);
}

// Hent adgangstoken
logMsg("Starting Monta sync (from: {$newFromDate})…");
$accessToken = getAccessToken($clientId, $clientSecret, $authEndpoint);

// Hent data fra Monta API med den udregnede fromDate og toDate
$data = getChargingData($accessToken, $dataEndpoint, $newFromDate, $toDate);

if ($data === null) {
    // 429 already logged in getChargingData; record the error in sync_log and stop
    $now = date('Y-m-d H:i:s');
    $db->prepare("INSERT OR REPLACE INTO sync_log (source, last_sync_timestamp, last_sync_count, updated_at, error_message)
                  VALUES ('monta', ?, 0, ?, 'Rate-limited (429) — retry næste kørsel')")
       ->execute([$now, $now]);
    exit(2);
}

// Gem data i databasen
$count = isset($data['data']) ? count($data['data']) : 0;
logMsg("Fetched {$count} charges from API.");
saveChargingData($db, $data);

// ── Skriv sync-status til sync_log ───────────────────────────────────────────
$now   = date('Y-m-d H:i:s');
$db->prepare("INSERT OR REPLACE INTO sync_log (source, last_sync_timestamp, last_sync_count, updated_at, error_message)
              VALUES ('monta', ?, ?, ?, NULL)")
   ->execute([$now, $count, $now]);

// Drop charge-dependent caches so newly synced charges show up immediately
// rather than waiting out the TTL (dashboard, analytics, annual, etc.).
if ($count > 0) {
    QueryBuilder::invalidateChargeCaches();
}

logMsg("Sync completed. {$count} charges synced.");
// ─────────────────────────────────────────────────────────────────────────────
