<?php
$db = new PDO('sqlite:Z:/monta/data/charging_data.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Antal charges per maaned ===" . PHP_EOL;
foreach ($db->query("SELECT strftime('%Y-%m', stoppedAt) AS mo, COUNT(*) AS cnt, ROUND(SUM(consumedKwh),1) AS kwh FROM charges WHERE stoppedAt IS NOT NULL GROUP BY mo ORDER BY mo DESC LIMIT 24") as $r)
    echo "  {$r['mo']}  ladninger={$r['cnt']}  kWh={$r['kwh']}" . PHP_EOL;

echo PHP_EOL . "=== Max stoppedAt i charges ===" . PHP_EOL;
foreach ($db->query("SELECT MAX(stoppedAt) AS mx FROM charges") as $r)
    echo "  " . $r['mx'] . PHP_EOL;

echo PHP_EOL . "=== sync_log ===" . PHP_EOL;
foreach ($db->query("SELECT * FROM sync_log ORDER BY source") as $r)
    echo "  source={$r['source']}  last_sync={$r['last_sync_timestamp']}  count={$r['last_sync_count']}  error=" . ($r['error_message'] ?? 'NULL') . PHP_EOL;

echo PHP_EOL . "=== vehicleId fordeling i charges ===" . PHP_EOL;
foreach ($db->query("SELECT vehicleId, COUNT(*) AS cnt, ROUND(SUM(consumedKwh),1) AS kwh FROM charges GROUP BY vehicleId ORDER BY vehicleId") as $r)
    echo "  vehicleId={$r['vehicleId']}  ladninger={$r['cnt']}  kWh={$r['kwh']}" . PHP_EOL;

echo PHP_EOL . "=== Charges med vehicleId=1 (Ukendt) og kWh>0 ===" . PHP_EOL;
$n = 0;
foreach ($db->query("SELECT id, vehicleId, cablePluggedInAt, stoppedAt, consumedKwh FROM charges WHERE vehicleId=1 AND consumedKwh>0 ORDER BY stoppedAt DESC LIMIT 20") as $r) {
    echo "  pluggedIn={$r['cablePluggedInAt']}  stopped={$r['stoppedAt']}  kwh={$r['consumedKwh']}" . PHP_EOL;
    $n++;
}
if ($n === 0) echo "  Ingen" . PHP_EOL;

echo PHP_EOL . "=== Matchanalyse: charges vs vehicle_charges ===" . PHP_EOL;
$charges = $db->query("SELECT id, vehicleId, cablePluggedInAt, stoppedAt, consumedKwh FROM charges WHERE consumedKwh > 0 ORDER BY stoppedAt DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$vcs     = $db->query("SELECT vehicleId, cablePluggedInAt FROM vehicle_charges ORDER BY rowid DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
$noMatch = 0; $mismatch = 0;
foreach ($charges as $c) {
    $tCharge = strtotime($c['cablePluggedInAt']);
    if ($tCharge === false) continue;
    $bestDiff = PHP_INT_MAX; $bestVid = null;
    foreach ($vcs as $vc) {
        $t = strtotime($vc['cablePluggedInAt']);
        if ($t === false) continue;
        $diff = abs($tCharge - $t);
        if ($diff < $bestDiff) { $bestDiff = $diff; $bestVid = $vc['vehicleId']; }
    }
    if ($bestDiff >= 3600) {
        echo "  NO_MATCH  charge_vid={$c['vehicleId']}  pluggedIn={$c['cablePluggedInAt']}  kwh={$c['consumedKwh']}" . PHP_EOL;
        $noMatch++;
    } elseif ((int)$bestVid !== (int)$c['vehicleId']) {
        $diffMin = round($bestDiff/60);
        echo "  MISMATCH  charge_vid={$c['vehicleId']}  vc_vid={$bestVid}  pluggedIn={$c['cablePluggedInAt']}  kwh={$c['consumedKwh']}  diff={$diffMin}min" . PHP_EOL;
        $mismatch++;
    }
}
echo PHP_EOL . "  Total: $noMatch ingen-match, $mismatch mismatch ud af " . count($charges) . " charges (kWh>0)" . PHP_EOL;
