<?php
/**
 * The Monta import: MontaSync + ChargeImporter against an in-memory charges schema (the real migrations)
 * and a fake Monta API. Money-relevant and was untested. Run:  php dev/test_monta_sync.php
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
function chargesDb(): PDO {
    $db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    ob_start(); MigrationRunner::run($db, __DIR__ . '/../migrations/charges'); ob_end_clean();
    return $db;
}
final class FakeMonta implements MontaApi {
    public array $calls = [];
    public function __construct(private ?array $charges, private ?Throwable $tokenError = null, private ?Throwable $chargesError = null) {}
    public function token(): string { if ($this->tokenError) throw $this->tokenError; return 'tok'; }
    public function charges(string $token, string $from, string $to): ?array {
        $this->calls[] = [$from, $to];
        if ($this->chargesError) throw $this->chargesError;
        return $this->charges;
    }
}
$charge = fn(int $id, array $x = []) => array_merge([
    'id' => $id, 'chargePointId' => 7, 'createdAt' => '2026-03-01T10:00:00+01:00', 'updatedAt' => '2026-03-01T12:00:00+01:00',
    'cablePluggedInAt' => '2026-03-01T10:00:00+01:00', 'startedAt' => '2026-03-01T10:05:00+01:00', 'stoppedAt' => '2026-03-01T12:00:00+01:00',
    'state' => 'completed', 'consumedKwh' => 20.5, 'kwhLimit' => null, 'startMeterKwh' => 100.0, 'endMeterKwh' => 120.5, 'cost' => 41.0,
    'stopReason' => 'EVDisconnected', 'soc' => ['percentage' => 80], 'socLimit' => 100], $x);
$logged = [];
$log = function (string $m, string $l = 'INFO') use (&$logged) { $logged[] = "$l: $m"; };
$now = strtotime('2026-03-02 08:00:00 UTC');
$one = fn(PDO $db, int $id) => $db->query("SELECT * FROM charges WHERE id = $id")->fetch();
$plug = fn(PDO $db, int $vehicle, string $at) => $db->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (?, ?, 1000)")->execute([$vehicle, $at]);

echo "timestamps\n";
check('an offset is normalised to UTC with Z', ChargeImporter::toUtcZ('2026-03-01T10:00:00+01:00') === '2026-03-01T09:00:00Z');
check('summer time (+02:00) too', ChargeImporter::toUtcZ('2026-07-01T10:00:00+02:00') === '2026-07-01T08:00:00Z');
check('a Z timestamp is left as it is', ChargeImporter::toUtcZ('2026-03-01T09:00:00Z') === '2026-03-01T09:00:00Z');
check('null and empty pass through', ChargeImporter::toUtcZ(null) === null && ChargeImporter::toUtcZ('') === '');
check('an unparseable value is kept (not lost)', ChargeImporter::toUtcZ('not a date') === 'not a date');

echo "fromDate\n";
$db = chargesDb();
check('an empty database starts at the beginning of the Monta history', MontaSync::fromDate($db) === MontaSync::DEFAULT_FROM);
$db->exec("INSERT INTO charges (id, stoppedAt) VALUES (1, '2026-03-01T09:00:00Z'), (2, '2026-02-01T09:00:00Z'), (3, NULL)");
check('it starts one second after the newest stoppedAt (an unfinished charge does not count)', MontaSync::fromDate($db) === '2026-03-01T09:00:01Z');
$db2 = chargesDb();
$db2->exec("INSERT INTO charges (id, stoppedAt) VALUES (1, CAST('2026-01-01T00:00:00Z' AS BLOB)), (2, '2026-03-01T09:00:00Z')");
check('a BLOB-typed stoppedAt does not hide the real maximum', MontaSync::fromDate($db2) === '2026-03-01T09:00:01Z', MontaSync::fromDate($db2));

echo "import: insert and update\n";
$db = chargesDb(); $plug($db, 2, '2026-03-01T08:30:00Z');
$api = new FakeMonta([$charge(101)]);
$code = MontaSync::run($db, $api, $log, $now);
$c = $one($db, 101);
check('a new charge is inserted, exit 0', $code === 0 && $c !== false);
check('timestamps are stored as UTC', $c['startedAt'] === '2026-03-01T09:05:00Z' && $c['stoppedAt'] === '2026-03-01T11:00:00Z' && $c['createdAt'] === '2026-03-01T09:00:00Z');
check('the numbers and soc come through', (float) $c['consumedKwh'] === 20.5 && (float) $c['cost'] === 41.0 && (float) $c['socPercentage'] === 80.0 && $c['state'] === 'completed');
check('the car is paired from the plug-in reading (matched)', (int) $c['vehicleId'] === 2 && $c['pairingSource'] === 'matched');
check('the vehicle row exists', (int) $db->query("SELECT COUNT(*) FROM vehicles WHERE id = 2")->fetchColumn() === 1);
check('the fetch window ends an hour after now', $api->calls[0][1] === '2026-03-02T09:00:00Z' && $api->calls[0][0] === MontaSync::DEFAULT_FROM);
$sl = $db->query("SELECT * FROM sync_log WHERE source = 'monta'")->fetch();
check('sync_log: ok, count 1, no error, run time', (int) $sl['last_sync_count'] === 1 && $sl['error_message'] === null && $sl['updated_at'] === date('Y-m-d H:i:s', $now));
check('one summary line is logged', (bool) array_filter($logged, fn($l) => str_contains($l, 'Synced 1 charges') && str_contains($l, '1 new, 0 updated')));

$logged = [];
$api = new FakeMonta([$charge(101, ['consumedKwh' => 22.0, 'state' => 'completed', 'cost' => 44.0])]);
MontaSync::run($db, $api, $log, $now);
check('the same charge again is updated, not duplicated', (int) $db->query("SELECT COUNT(*) FROM charges WHERE id = 101")->fetchColumn() === 1 && (float) $one($db, 101)['consumedKwh'] === 22.0 && (float) $one($db, 101)['cost'] === 44.0);
check('an update does not touch createdAt / plug-in time', $one($db, 101)['createdAt'] === '2026-03-01T09:00:00Z' && $one($db, 101)['cablePluggedInAt'] === '2026-03-01T09:00:00Z');
check('the log says 0 new, 1 updated', (bool) array_filter($logged, fn($l) => str_contains($l, '0 new, 1 updated')));
$api = new FakeMonta([$charge(101, ['state' => 'charging', 'stoppedAt' => null])]);
MontaSync::run($db, $api, $log, $now);
check('a running charge has no stoppedAt', $one($db, 101)['stoppedAt'] === null && $one($db, 101)['state'] === 'charging');

echo "pairing\n";
$db = chargesDb();
$db->exec("INSERT INTO vehicles (id, vehicleName) VALUES (2, 'Enyaq'), (3, 'e-Up')");
$plug($db, 3, '2026-03-01T06:00:00Z'); $plug($db, 2, '2026-03-01T08:30:00Z');
MontaSync::run($db, new FakeMonta([$charge(1)]), $log, $now);
check('the most recent plug-in before the charge wins', (int) $one($db, 1)['vehicleId'] === 2);
$db->exec("DELETE FROM charges");
MontaSync::run($db, new FakeMonta([$charge(2, ['cablePluggedInAt' => '2026-03-01T07:00:00Z', 'startedAt' => '2026-03-01T07:05:00Z', 'createdAt' => '2026-03-01T07:00:00Z'])]), $log, $now);
check('an earlier charge pairs with the car plugged in then', (int) $one($db, 2)['vehicleId'] === 3 && $one($db, 2)['pairingSource'] === 'matched');
MontaSync::run($db, new FakeMonta([$charge(3, ['vehicleId' => 3])]), $log, $now);
check("Monta's own vehicleId is trusted (pairingSource api)", (int) $one($db, 3)['vehicleId'] === 3 && $one($db, 3)['pairingSource'] === 'api');
$db->exec("UPDATE charges SET vehicleId = 2, pairingSource = 'manual' WHERE id = 3");
MontaSync::run($db, new FakeMonta([$charge(3, ['vehicleId' => 3])]), $log, $now);
check('a manual correction survives a re-sync', (int) $one($db, 3)['vehicleId'] === 2 && $one($db, 3)['pairingSource'] === 'manual');
$nodb = chargesDb();
MontaSync::run($nodb, new FakeMonta([$charge(9)]), $log, $now);
check('with no plug-in readings at all the car is unknown (null) - never a guessed default', $one($nodb, 9)['vehicleId'] === null && $one($nodb, 9)['pairingSource'] === 'unknown');
$logged = [];
MontaSync::run($nodb, new FakeMonta([$charge(10, ['cablePluggedInAt' => null, 'startedAt' => null, 'createdAt' => null])]), $log, $now);
check('a charge with no timestamp at all is stored as unknown, with a warning', $one($nodb, 10)['pairingSource'] === 'unknown' && (bool) array_filter($logged, fn($l) => str_starts_with($l, 'WARN') && str_contains($l, 'timestamp')));

echo "bad input\n";
$db = chargesDb(); $logged = [];
MontaSync::run($db, new FakeMonta([$charge(5), ['state' => 'completed', 'consumedKwh' => 3], $charge(6)]), $log, $now);
check('a charge without an id is skipped with a warning; the others are stored', (int) $db->query("SELECT COUNT(*) FROM charges")->fetchColumn() === 2 && (bool) array_filter($logged, fn($l) => str_contains($l, 'without id')));
$logged = [];
MontaSync::run($db, new FakeMonta([$charge(7, ['consumedKwh' => null, 'cost' => null, 'soc' => null, 'socLimit' => null])]), $log, $now);
check('missing numbers become NULL without notices', $one($db, 7)['consumedKwh'] === null && $one($db, 7)['socPercentage'] === null);
$partial = $charge(8); unset($partial['stopReason'], $partial['kwhLimit']);
MontaSync::run($db, new FakeMonta([$partial]), $log, $now);
check('missing keys are tolerated', $one($db, 8) !== false);

echo "all or nothing\n";
$db = chargesDb();
$db->exec("CREATE TRIGGER no13 BEFORE INSERT ON charges WHEN NEW.id = 13 BEGIN SELECT RAISE(ABORT, 'rejected'); END");
$thrown = null;
try { MontaSync::run($db, new FakeMonta([$charge(11), $charge(12), $charge(13)]), $log, $now); } catch (\Throwable $e) { $thrown = $e->getMessage(); }
check('one charge that cannot be stored rolls the whole batch back and throws', $thrown !== null && (int) $db->query("SELECT COUNT(*) FROM charges")->fetchColumn() === 0);
check('...so the next run fetches the same window again (nothing was lost, nothing half-stored)', MontaSync::fromDate($db) === MontaSync::DEFAULT_FROM);
check('...and the failed run did not mark itself as a success in sync_log', $db->query("SELECT COUNT(*) FROM sync_log WHERE source = 'monta' AND error_message IS NULL")->fetchColumn() == 0);

echo "API outcomes\n";
$db = chargesDb();
$code = MontaSync::run($db, new FakeMonta(null), $log, $now);
$sl = $db->query("SELECT * FROM sync_log WHERE source = 'monta'")->fetch();
check('rate-limited / network down: exit 2 and an error in sync_log', $code === 2 && str_contains((string) $sl['error_message'], 'Rate-limited'));
$code = MontaSync::run($db, new FakeMonta([]), $log, $now);
$sl = $db->query("SELECT * FROM sync_log WHERE source = 'monta'")->fetch();
check('the next good run (even with 0 charges) clears the error', $code === 0 && $sl['error_message'] === null && (int) $sl['last_sync_count'] === 0);
$thrown = null;
try { MontaSync::run($db, new FakeMonta([], new MontaApiException('Could not get an access token: HTTP 401', 401)), $log, $now); } catch (MontaApiException $e) { $thrown = $e->httpCode; }
check('a token failure throws (CronJob records it)', $thrown === 401);
$thrown = null;
try { MontaSync::run($db, new FakeMonta([], null, new MontaApiException('Fetching charges failed: HTTP 500', 500)), $log, $now); } catch (MontaApiException $e) { $thrown = $e->httpCode; }
check('a failing fetch throws', $thrown === 500);
$hit = 0;
MontaSync::run($db, new FakeMonta([]), $log, $now, function () use (&$hit) { $hit++; });
MontaSync::run($db, new FakeMonta([$charge(20)]), $log, $now, function () use (&$hit) { $hit++; });
check('caches are invalidated only when charges came in', $hit === 1);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
