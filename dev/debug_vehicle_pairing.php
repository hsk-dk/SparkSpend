<?php
require dirname(__DIR__) . '/includes/configuration.php';
require dirname(__DIR__) . '/includes/DatabaseManager.php';
$db = DatabaseManager::getChargesDb();

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

echo PHP_EOL . "=== Matchanalyse: seneste tilslutning <= T ===" . PHP_EOL;
// Mirrors getVehicleForCharge(): latest HA plug-in at/before T (+grace),
// bounded by max look-back, with ambiguity detection.
$graceAfter  = Config::pairingGraceAfterSec();
$maxLookback = Config::pairingMaxLookbackSec();
$ambiguity   = Config::pairingAmbiguitySec();
echo "  Grace: +{$graceAfter}s, max look-back: " . round($maxLookback/3600) . "h, tvetydighed: {$ambiguity}s" . PHP_EOL;

$charges = $db->query("SELECT id, vehicleId, cablePluggedInAt, stoppedAt, consumedKwh FROM charges WHERE consumedKwh > 0 ORDER BY stoppedAt DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$vcs     = $db->query("SELECT vehicleId, cablePluggedInAt FROM vehicle_charges ORDER BY rowid DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
$vcParsed = [];
foreach ($vcs as $vc) {
    $t = $vc['cablePluggedInAt'] ? strtotime($vc['cablePluggedInAt']) : false;
    if ($t !== false) $vcParsed[] = ['vid' => (int)$vc['vehicleId'], 'ts' => $t];
}
usort($vcParsed, fn($a, $b) => $a['ts'] <=> $b['ts']);

$noMatch = 0; $ambiguous = 0; $stale = 0; $mismatch = 0;
foreach ($charges as $c) {
    $T = $c['cablePluggedInAt'] ? strtotime($c['cablePluggedInAt']) : false;
    if ($T === false) continue;
    $cutoff = $T + $graceAfter;

    $eligible = [];
    foreach ($vcParsed as $vc) { if ($vc['ts'] > $cutoff) break; $eligible[] = $vc; }
    if (empty($eligible)) {
        echo "  NO_MATCH   charge_vid={$c['vehicleId']}  pluggedIn={$c['cablePluggedInAt']}  kwh={$c['consumedKwh']}" . PHP_EOL;
        $noMatch++;
        continue;
    }
    $chosen = end($eligible);
    if (($T - $chosen['ts']) > $maxLookback) {
        echo "  STALE      chosen_vid={$chosen['vid']}  pluggedIn={$c['cablePluggedInAt']}  age=" . round(($T-$chosen['ts'])/3600,1) . "h" . PHP_EOL;
        $stale++;
        continue;
    }
    $prevOther = null;
    for ($i = count($eligible) - 2; $i >= 0; $i--) { if ($eligible[$i]['vid'] !== $chosen['vid']) { $prevOther = $eligible[$i]['ts']; break; } }
    if ($prevOther !== null && ($chosen['ts'] - $prevOther) <= $ambiguity) {
        echo "  AMBIGUOUS  chosen_vid={$chosen['vid']}  pluggedIn={$c['cablePluggedInAt']}  kwh={$c['consumedKwh']}" . PHP_EOL;
        $ambiguous++;
    } elseif ($chosen['vid'] !== (int)$c['vehicleId']) {
        echo "  MISMATCH   charge_vid={$c['vehicleId']}  chosen_vid={$chosen['vid']}  pluggedIn={$c['cablePluggedInAt']}  kwh={$c['consumedKwh']}" . PHP_EOL;
        $mismatch++;
    }
}
echo PHP_EOL . "  Total: $noMatch ingen-match, $stale for-gamle, $ambiguous tvetydige, $mismatch mismatch ud af " . count($charges) . " charges (kWh>0)" . PHP_EOL;
