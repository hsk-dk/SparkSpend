<?php
/**
 * DataIntegrity, and the guarantee behind it: every way the app WRITES a number leaves a number in the
 * column (not text, not a blob), even when the input is a string as it arrives from HTTP / JSON / Home
 * Assistant. A BLOB car id once made charges "belong to no car" because SQLite does not convert a blob in a
 * comparison. Run:  php dev/test_data_integrity.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';
require __DIR__ . '/../includes/autoload.php';
Config::load();

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
function freshDb(string $kind): PDO {
    $db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    ob_start(); MigrationRunner::run($db, __DIR__ . "/../migrations/$kind"); ob_end_clean();
    return $db;
}
$noop = function (string $m, string $l = 'INFO') {};
$damage = fn(PDO $db) => array_map(fn($d) => "{$d['table']}.{$d['column']}={$d['type']}", DataIntegrity::typeDamage($db));
$type = fn(PDO $db, string $sql) => $db->query($sql)->fetchColumn();

echo "detection\n";
$db = freshDb('charges');
$db->exec("INSERT INTO vehicles (id, vehicleName) VALUES (1, 'Enyaq'), (2, 'e-Up')");
$db->exec("INSERT INTO providers (providerName) VALUES ('Clever')");
check('a clean database has no damage and no orphans', DataIntegrity::typeDamage($db) === [] && DataIntegrity::orphans($db) === []);
$r = DataIntegrity::check($db, 'DB');
check('...and check() says so with one ok line', count($r) === 1 && $r[0]['level'] === 'ok');

$db->exec("INSERT INTO charges (id, vehicleId, pairingSource) VALUES (1, CAST('2' AS BLOB), 'matched')");
check('a car id stored as BLOB is found', $damage($db) === ['charges.vehicleId=blob'], json_encode($damage($db)));
check('...and that is exactly the case a comparison misses', (int) $db->query("SELECT COUNT(*) FROM charges WHERE vehicleId = 2")->fetchColumn() === 0);
$db->exec("UPDATE charges SET vehicleId = '2' WHERE id = 1");
check('a numeric string in an INTEGER column is converted by SQLite itself (affinity), so it is not damage', $damage($db) === [] && $type($db, "SELECT typeof(vehicleId) FROM charges WHERE id = 1") === 'integer');
$db->exec("UPDATE charges SET kwhLimit = CAST('7.5' AS BLOB), vehicleId = 1 WHERE id = 1");
check('a blob in a REAL column too (every numeric column is checked, not a fixed list)', $damage($db) === ['charges.kwhLimit=blob'], json_encode($damage($db)));
$db->exec("DELETE FROM charges");

$db->exec("CREATE TABLE odd (id INTEGER PRIMARY KEY, label INTEGER)");
$db->exec("INSERT INTO odd (label) VALUES ('Enyaq'), ('e-Up'), (''), (NULL)");
check('real text in a column that is merely DECLARED integer (vehicles.vehicleName is, in production) is not damage', $damage($db) === [], json_encode($damage($db)));
$db->exec("INSERT INTO odd (label) VALUES (CAST('12' AS BLOB))");
check('...but a numeric-looking blob in it is', $damage($db) === ['odd.label=blob']);
$db->exec("DROP TABLE odd");

$db->exec("INSERT INTO charges (id, vehicleId) VALUES (10, 1), (11, 99), (12, NULL)");
$db->exec("INSERT INTO ext_charges (vehicleId, providerId, kwh, pris, datetime) VALUES (1, 1, 5, 10, '2026-01-01T10:00'), (99, 77, 5, 10, '2026-01-01T10:00'), (1, 0, 5, 10, '2026-01-01T10:00')");
$db->exec("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (1, '2026-01-01T10:00:00Z', 100), (55, '2026-01-01T10:00:00Z', 100)");
$o = [];
foreach (DataIntegrity::orphans($db) as $x) $o["{$x['table']}.{$x['column']}"] = $x['count'];
check('orphans: a charge, an external charge, a provider and a reading that point at nothing', $o === ['charges.vehicleId' => 1, 'ext_charges.vehicleId' => 1, 'ext_charges.providerId' => 1, 'vehicle_charges.vehicleId' => 1], json_encode($o));
$levels = array_column(DataIntegrity::check($db, 'DB'), 'level');
check('orphans are warnings, type damage is a failure', in_array('warn', $levels, true) && !in_array('fail', $levels, true));
$db->exec("UPDATE charges SET vehicleId = CAST('1' AS BLOB) WHERE id = 10");
check('type damage makes check() fail', in_array('fail', array_column(DataIntegrity::check($db, 'DB'), 'level'), true));
check('a database without the app tables does not throw', DataIntegrity::check(new PDO('sqlite::memory:'), 'X')[0]['level'] === 'ok');

echo "every write leaves numbers as numbers (string input, as from HTTP / JSON)\n";
$db = freshDb('charges');
$db->exec("INSERT INTO vehicles (id, vehicleName) VALUES (1, 'Enyaq'), (2, 'e-Up')");
$db->exec("INSERT INTO providers (providerName) VALUES ('Clever')");

$ins = ChargeRepository::insertExternal($db, ['vehicleId' => '2', 'providerId' => '1', 'kwh' => '12.5', 'cost' => '45.0', 'chargeDate' => '2026-01-01T10:00']);
$id = $ins['id'];
check('ChargeRepository::insertExternal', $damage($db) === [] && $type($db, "SELECT typeof(vehicleId) FROM ext_charges WHERE id = $id") === 'integer' && $type($db, "SELECT typeof(kwh) FROM ext_charges WHERE id = $id") === 'real', json_encode($damage($db)));
ChargeRepository::updateExternal($db, ['id' => $id, 'vehicleId' => '1', 'providerId' => '1', 'kwh' => '20', 'cost' => '60', 'chargeDate' => '2026-01-02T10:00']);
check('ChargeRepository::updateExternal', $damage($db) === [] && (int) $type($db, "SELECT COUNT(*) FROM ext_charges WHERE vehicleId = 1") === 1);

$db->exec("INSERT INTO charges (id, vehicleId, pairingSource) VALUES (500, 1, 'matched')");
ChargeRepository::updateInternal($db, ['id' => '500', 'vehicleId' => '2']);
check('ChargeRepository::updateInternal', $damage($db) === [] && (int) $type($db, "SELECT COUNT(*) FROM charges WHERE vehicleId = 2") === 1);
$stmt = $db->prepare("UPDATE charges SET vehicleId = ?, pairingSource = 'manual' WHERE id = ?");   // the SQL routes/update_internal_charge.php runs
$stmt->execute([Http::int(['v' => '1'], 'v'), Http::int(['i' => '500'], 'i')]);
check('the route\'s own UPDATE (vehicleId through Http::int)', $damage($db) === [] && (int) $type($db, "SELECT COUNT(*) FROM charges WHERE vehicleId = 1") === 1);

VehicleRepository::insertOdometerReading($db, ['vehicleId' => '2', 'timestamp' => '2026-01-01T10:00:00Z', 'odometer' => '31000']);
check('VehicleRepository::insertOdometerReading', $damage($db) === [] && $type($db, "SELECT typeof(vehicleId) FROM vehicle_charges") === 'integer' && $type($db, "SELECT typeof(odometer) FROM vehicle_charges") === 'real', json_encode($damage($db)));
VehicleRepository::upsert($db, 3, 'Ny bil');
VehicleRepository::upsert($db, 3);
check('VehicleRepository::upsert', $damage($db) === [] && $type($db, "SELECT typeof(id) FROM vehicles WHERE id = 3") === 'integer');

$imp = new ChargeImporter($db, $noop);
$imp->import([['id' => '900', 'chargePointId' => '7', 'createdAt' => '2026-03-01T10:00:00+01:00', 'startedAt' => '2026-03-01T10:05:00+01:00', 'stoppedAt' => '2026-03-01T12:00:00+01:00',
    'cablePluggedInAt' => '2026-03-01T10:00:00+01:00', 'state' => 'completed', 'consumedKwh' => '20.5', 'cost' => '41', 'vehicleId' => '2', 'soc' => ['percentage' => '80']]]);
check('ChargeImporter with every number as a string (as a JSON API may send them)', $damage($db) === [] && $type($db, "SELECT typeof(vehicleId) FROM charges WHERE id = 900") === 'integer' && $type($db, "SELECT typeof(consumedKwh) FROM charges WHERE id = 900") === 'real', json_encode($damage($db)));
check('...and the whole database is still clean', DataIntegrity::orphans($db) === []);

$pl = freshDb('powerlog');
MeterSync::store($pl, 'powerloghus', [['logdate' => '2026-01-01 10:00:00', 'kwh' => '1234.5']], $noop);
MeterSync::store($pl, 'powerlogjord', [['logdate' => '2026-01-01 10:00:00', 'kwh' => '99']], $noop);
check('MeterSync::store (kWh from MySQL arrives as strings)', $damage($pl) === [] && $type($pl, "SELECT typeof(kwh) FROM powerloghus") === 'real');
DeviceEnergy::store($pl, [['day' => '2026-01-01', 'device' => 'varme_skur', 'kwh' => 4.25]]);
SyncLog::success($pl, 'x', 3, '2026-01-01 10:00:00');
check('DeviceEnergy::store and SyncLog', $damage($pl) === [] && $type($pl, "SELECT typeof(kwh) FROM device_energy") === 'real' && $type($pl, "SELECT typeof(last_sync_count) FROM sync_log") === 'integer');
check('the powerlog database is clean too', array_column(DataIntegrity::check($pl, 'P'), 'level') === ['ok']);

echo "\ncontent(): implausible rows of the last 90 days\n";
$now = strtotime('2026-11-20 12:00:00');
$cdb = freshDb('charges');
$cdb->exec("INSERT INTO vehicles (id, vehicleName) VALUES (1, 'Enyaq')");
$cdb->exec("INSERT INTO providers (providerName) VALUES ('Clever')");
$ins = function (int $id, array $f) use ($cdb) {
    $f += ['vehicleId' => 1, 'startedAt' => '2026-11-10T10:00:00Z', 'stoppedAt' => '2026-11-10T12:00:00Z', 'consumedKwh' => 20, 'cost' => 40];
    $cdb->prepare("INSERT INTO charges (id, vehicleId, startedAt, stoppedAt, consumedKwh, cost, state, pairingSource) VALUES (?, ?, ?, ?, ?, ?, 'completed', 'matched')")
        ->execute([$id, $f['vehicleId'], $f['startedAt'], $f['stoppedAt'], $f['consumedKwh'], $f['cost']]);
};
$names = fn(array $r) => implode(' | ', array_column($r, 'name'));
$ins(1, []);
$cdb->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (1, ?, ?)")->execute(['2026-11-05T18:00:00+01:00', 1000]);
$cdb->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (1, ?, ?)")->execute(['2026-11-12T18:00:00+01:00', 1150]);
$r = DataIntegrity::content($cdb, 'EV', $now);
check('plausible rows: one ok line', count($r) === 1 && $r[0]['level'] === 'ok' && str_contains($r[0]['name'], '90 dage'), $names($r));

$ins(2, ['startedAt' => '2026-11-11T10:00:00Z', 'stoppedAt' => '2026-11-11T09:00:00Z']);
$r = DataIntegrity::content($cdb, 'EV', $now);
check('a stop before the start (with kWh) is found, with an example id', count($r) === 1 && $r[0]['level'] === 'warn' && str_contains($r[0]['name'], '1 ladninger, hvor stop ligger før start') && str_contains($r[0]['detail'], 'id 2'), $names($r));
$cdb->exec("UPDATE charges SET consumedKwh = 0 WHERE id = 2");
check('...but not when the session holds no kWh (it changes no figure)', DataIntegrity::content($cdb, 'EV', $now)[0]['level'] === 'ok');
$cdb->exec("DELETE FROM charges WHERE id = 2");

$ins(3, ['consumedKwh' => 400, 'startedAt' => '2026-11-12T10:00:00Z', 'stoppedAt' => '2026-11-12T20:00:00Z']);
$ins(4, ['cost' => -5, 'startedAt' => '2026-11-13T10:00:00Z', 'stoppedAt' => '2026-11-13T12:00:00Z']);
$r = DataIntegrity::content($cdb, 'EV', $now);
check('absurd kWh and a negative price are counted together', count($r) === 1 && str_contains($r[0]['name'], '2 ladninger med negative eller urealistiske'), $names($r));
$cdb->exec("DELETE FROM charges WHERE id IN (3, 4)");

$ins(5, ['stoppedAt' => null, 'startedAt' => '2026-11-15T10:00:00Z']);
$ins(6, ['stoppedAt' => null, 'startedAt' => '2026-11-20T09:00:00Z']);
$r = DataIntegrity::content($cdb, 'EV', $now);
check('a session open for more than 2 days is found; one started this morning is not', count($r) === 1 && str_contains($r[0]['name'], '1 ladninger uden stoptid') && str_contains($r[0]['detail'], 'id 5'), $names($r));
$cdb->exec("DELETE FROM charges WHERE id IN (5, 6)");

$ins(7, ['cost' => null, 'startedAt' => '2026-11-14T10:00:00Z', 'stoppedAt' => '2026-11-14T12:00:00Z']);
$r = DataIntegrity::content($cdb, 'EV', $now);
check('a charge with kWh but no price is found', count($r) === 1 && str_contains($r[0]['name'], '1 ladninger med kWh men uden pris'), $names($r));
$cdb->exec("DELETE FROM charges WHERE id = 7");

$cdb->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (1, ?, 0)")->execute(['2026-11-14T18:00:00+01:00']);
$r = DataIntegrity::content($cdb, 'EV', $now);
check('an odometer reading of 0 is found, and is not also reported as "backwards"', count($r) === 1 && str_contains($r[0]['name'], '1 kilometeraflæsninger på 0'), $names($r));
$cdb->exec("DELETE FROM vehicle_charges WHERE odometer = 0");
$cdb->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (1, ?, ?)")->execute(['2026-11-16T18:00:00+01:00', 1100]);
$r = DataIntegrity::content($cdb, 'EV', $now);
check('a reading lower than the one before (1100 after 1150) is found', count($r) === 1 && str_contains($r[0]['name'], '1 kilometeraflæsninger lavere end den forrige'), $names($r));
$cdb->exec("DELETE FROM vehicle_charges WHERE odometer = 1100");

$cdb->prepare("INSERT INTO ext_charges (vehicleId, providerId, kwh, pris, datetime) VALUES (1, 1, 0, 10, ?)")->execute(['2026-11-15 12:00:00']);
check('an external charge with 0 kWh is found', str_contains($names(DataIntegrity::content($cdb, 'EV', $now)), '1 eksterne ladninger'));
$cdb->exec("DELETE FROM ext_charges");

echo "\nonly the last 90 days (old history stays quiet)\n";
$ins(8, ['stoppedAt' => null, 'startedAt' => '2025-11-27T07:25:00Z']);
$cdb->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (1, ?, 0)")->execute(['2025-11-19T18:27:05+01:00']);
check('a stuck session and a 0 reading from a year ago are not reported', DataIntegrity::content($cdb, 'EV', $now)[0]['level'] === 'ok');
check('...they are when the window reaches them (the same data as of 5 Dec 2025)', count(DataIntegrity::content($cdb, 'EV', strtotime('2025-12-05'))) >= 2);
check('the window can be set', DataIntegrity::content($cdb, 'EV', $now, 400)[0]['level'] === 'warn');

echo "\nmissing tables\n";
$empty = new PDO('sqlite::memory:'); $empty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r = DataIntegrity::content($empty, 'X', $now);
check('a database without the tables: ok, no exception', count($r) === 1 && $r[0]['level'] === 'ok');
check('the powerlog database has none of those tables: ok', DataIntegrity::content(freshDb('powerlog'), 'P', $now)[0]['level'] === 'ok');
check('doctor.php runs the content check on the charges database', str_contains(file_get_contents(__DIR__ . '/../doctor.php'), "DataIntegrity::content(\$db, \$label)"));

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
