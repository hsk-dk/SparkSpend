<?php
/**
 * Seed a local test instance with SYNTHETIC data (no production data involved).
 *
 *   php dev/seed_dev.php            # (re)creates data-dev/*.db
 *
 * Then:  SPARKSPEND_ENV_FILE=.env.dev php -S localhost:8080
 * Data is relative to today, deterministic (fixed seed) and covers ~2 years.
 */
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/MigrationRunner.php';

$root = dirname(__DIR__);
$dir  = getenv('SEED_DIR') ?: $root . '/data-dev';   // SEED_DIR: seed somewhere else (dev/test_routes.php)
if (!is_dir($dir . '/cache')) mkdir($dir . '/cache', 0777, true);
foreach (['charging_data.db', 'powerlog_data.db'] as $f) {
    foreach (['', '-wal', '-shm'] as $sfx) @unlink("$dir/$f$sfx");
}

function openDb(string $path, string $migrations): PDO {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    MigrationRunner::run($db, $migrations);
    return $db;
}
$ch = openDb("$dir/charging_data.db", "$root/migrations/charges");
$pl = openDb("$dir/powerlog_data.db", "$root/migrations/powerlog");

mt_srand(42);
$now  = time();
$iso  = fn(int $t) => gmdate('Y-m-d\TH:i:s\Z', $t);
$rand = fn(float $a, float $b) => $a + mt_rand() / mt_getrandmax() * ($b - $a);

// ── Vehicles / providers ────────────────────────────────────────────────
$ch->exec("INSERT INTO vehicles (id, vehicleName) VALUES (1, 'Testbil A'), (2, 'Testbil B')");
$ch->exec("INSERT INTO providers (providerName) VALUES ('Fast Charge DK'), ('Supermarked'), ('Arbejdsplads')");

// ── Home charges (Monta-style) + odometer readings ───────────────────────
$odo = [1 => 30000.0, 2 => 20000.0];
$cs = $ch->prepare("INSERT INTO charges (id, chargePointId, createdAt, updatedAt, cablePluggedInAt, startedAt, stoppedAt,
    state, consumedKwh, startMeterKwh, endMeterKwh, cost, stopReason, socPercentage, socLimit, vehicleId, pairingSource)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$os = $ch->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (?,?,?)");
$ch->beginTransaction();
$meter = 10000.0; $id = 900000000000000000; $t = $now - 700 * 86400; $n = 0;
while ($t < $now - 3600 * 6) {
    $t += (int) $rand(1.0, 3.2) * 86400 + (int) $rand(0, 6) * 3600;
    if ($t >= $now - 3600 * 6) break;
    $veh  = mt_rand(0, 9) < 6 ? 1 : 2;
    $plug = $t - (int) $rand(0, 3) * 3600;
    $odo[$veh] += $rand(40, 280);
    $os->execute([$veh, $iso($plug), round($odo[$veh])]);
    $kwh   = round($rand(4, 42), 2);
    $start = $t; $stop = $start + (int) ($kwh / 7.2 * 3600);
    $price = round($kwh * $rand(1.2, 3.1), 2);
    $endMeter = $meter + $kwh;
    $cs->execute([++$id, 4315812, $iso($plug), $iso($stop), $iso($plug), $iso($start), $iso($stop), 'completed',
        $kwh, round($meter, 3), round($endMeter, 3), $price, 'StoppedBySoCLimit', 80, 80, $veh, 'matched']);
    $meter = $endMeter;
    $n++;
}
// a zero-kWh + uncertain row to exercise the UI
$cs->execute([++$id, 4315812, $iso($now - 5 * 86400), $iso($now - 5 * 86400), null, null, $iso($now - 5 * 86400),
    'completed', 0, $meter, $meter, 0, 'Unknown', null, null, 1, 'uncertain']);
// external charges
$es = $ch->prepare("INSERT INTO ext_charges (vehicleId, providerId, kwh, pris, datetime) VALUES (?,?,?,?,?)");
for ($i = 0; $i < 25; $i++) {
    $kwh = round($rand(10, 55), 2);
    $es->execute([mt_rand(1, 2), mt_rand(1, 3), $kwh, round($kwh * $rand(2.5, 5.5), 2), $iso($now - mt_rand(2, 650) * 86400)]);
}
$ch->commit();

// ── Charger events (Home Assistant) — last 10 days ──────────────────────────
$ev = $ch->prepare("INSERT OR IGNORE INTO charger_events (eventAt, receivedAt, event, chargerStatus, sessionEnergy, lifetimeEnergy, power, cars)
    VALUES (?,?,?,?,?,?,?,?)");
$life = 15000.0;
for ($d = 10; $d >= 1; $d -= 2) {
    $b = $now - $d * 86400; $e = round($rand(8, 30), 2); $car = mt_rand(1, 2);
    $snap = json_encode([(string) $car => ['soc' => mt_rand(20, 60), 'odometer' => round($odo[$car]), 'cable' => 'on', 'carTs' => $iso($b)]]);
    foreach ([['plugged', 'awaiting_authorization', null, 0, 0], ['charge_start', 'charging', null, 600, 7.2],
              ['charge_end', 'completed', $e, 3600 * 4, 0], ['unplugged', 'disconnected', $e, 3600 * 9, 0]] as [$name, $st, $en, $off, $pw]) {
        if ($en) $life += $e / 2;
        $ev->execute([$iso($b + $off), $iso($b + $off + 2), $name, $st, $en, round($life, 2), $pw, $snap]);
    }
}

// ── Sync log ────────────────────────────────────────────────────────────
$ch->prepare("INSERT OR REPLACE INTO sync_log (source, last_sync_timestamp, last_sync_count, updated_at, error_message) VALUES ('monta', ?, ?, ?, NULL)")
   ->execute([$iso($now - 600), $n, $iso($now - 600)]);

// ── Powerlog: hourly cumulative meters (local time, 'Y-m-d H:00:00') ──────────
$jord = $pl->prepare("INSERT INTO powerlogjord (logdate, kwh) VALUES (?, ?)");
$hus  = $pl->prepare("INSERT INTO powerloghus (logdate, kwh) VALUES (?, ?)");
$pl->beginTransaction();
$jk = 40000.0; $hk = 100000.0;
$startH = strtotime(date('Y-m-d H:00:00', $now - 730 * 86400));
for ($h = $startH; $h <= $now; $h += 3600) {
    $month = (int) date('n', $h); $hour = (int) date('G', $h);
    $cold  = 1 + 0.9 * cos(($month - 1) / 12 * 2 * M_PI);   // winter heavier
    $jDelta = $rand(0.2, 0.6) * $cold * (($hour >= 6 && $hour <= 22) ? 1.1 : 0.7);
    $jk += $jDelta;
    // house meter includes heat pump + rest
    $hk += $jDelta + $rand(0.3, 0.8) * (($hour >= 17 && $hour <= 22) ? 1.8 : 1.0);
    $ts = date('Y-m-d H:00:00', $h);
    $jord->execute([$ts, round($jk, 3)]);
    $hus->execute([$ts, round($hk, 3)]);
}
$pl->commit();
$sl = $pl->prepare("INSERT OR REPLACE INTO sync_log (source, last_sync_timestamp, last_sync_count, updated_at, error_message) VALUES (?, ?, 0, ?, NULL)");
foreach (['powerlogjord', 'powerloghus'] as $src) {
    $sl->execute([$src, date('Y-m-d H:00:00', $now), $iso($now)]);
}

echo "Seeded $dir  ({$n} charges, 25 external, hourly meters for ~2 years)\n";
echo "Run: SPARKSPEND_ENV_FILE=.env.dev php -S localhost:8080\n";
