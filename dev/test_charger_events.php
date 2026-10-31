<?php
/**
 * Unit tests for includes/ChargerEvents.php (validation) and
 * includes/ChargerSessions.php (session assembly). No database needed.
 * Run:  php dev/test_charger_events.php
 *
 * dev/fixtures/charger_events_sample.json is real data: three charging sessions
 * (26-30 Oct 2026) replayed through the Home Assistant automation's event rules.
 */
require __DIR__ . '/../includes/ChargerEvents.php';
require __DIR__ . '/../includes/ChargerSessions.php';

$fails = 0; $n = 0;
function check(string $name, $actual, $expected, float $eps = 1e-9): void {
    global $fails, $n; $n++;
    $ok = ($actual === $expected) || (is_numeric($actual) && is_numeric($expected) && !is_string($actual) && abs($actual - $expected) <= $eps);
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++;
    echo "  FAIL $name\n       expected " . json_encode($expected) . "\n       got      " . json_encode($actual) . "\n";
}
function checkLoose(string $name, $actual, $expected): void {   // == instead of === (JSON drops the ".0" of floats)
    check($name, $actual == $expected, true);
}
$NOW = strtotime('2026-10-31T08:00:00Z');
$v = fn(array $in) => ChargerEvents::validate($in, $NOW);
$ok = ['event' => 'plugged', 'eventAt' => '2026-10-30T19:04:09Z'];

echo "ChargerEvents::validate()\n";
$r = $v($ok);
check('minimal valid event', [$r['ok'], $r['row']['event'], $r['row']['eventAt']], [true, 'plugged', '2026-10-30T19:04:09Z']);
check('HA isoformat with microseconds and +00:00 -> UTC Z seconds', $v(['event' => 'charge_start', 'eventAt' => '2026-10-30T00:29:53.839000+00:00'])['row']['eventAt'], '2026-10-30T00:29:53Z');
check('other UTC offsets are converted', $v(['event' => 'charge_start', 'eventAt' => '2026-10-30T01:29:53+01:00'])['row']['eventAt'], '2026-10-30T00:29:53Z');
check('unknown event rejected', $v(['event' => 'explode', 'eventAt' => $ok['eventAt']])['ok'], false);
check('missing event rejected', $v(['eventAt' => $ok['eventAt']])['ok'], false);
foreach (['', 'tomorrow', 'yesterday 12:00', '2026-10-30', 123, null, str_repeat('2', 60)] as $bad) {
    check('eventAt ' . json_encode($bad) . ' rejected', $v(['event' => 'plugged', 'eventAt' => $bad])['ok'], false);
}
check('5 min in the future is tolerated', $v(['event' => 'plugged', 'eventAt' => '2026-10-31T08:05:00Z'])['ok'], true);
check('1 h in the future is rejected', $v(['event' => 'plugged', 'eventAt' => '2026-10-31T09:00:00Z'])['ok'], false);
check('older than 30 days is rejected', $v(['event' => 'plugged', 'eventAt' => '2026-09-01T00:00:00Z'])['ok'], false);

$r = $v($ok + ['sessionEnergy' => '36.449', 'lifetimeEnergy' => 15307.8, 'power' => 'unknown']);
check('numeric strings accepted, "unknown" -> null', [$r['row']['sessionEnergy'], $r['row']['lifetimeEnergy'], $r['row']['power']], [36.449, 15307.8, null]);
check('negative energy rejected', $v($ok + ['sessionEnergy' => -5])['ok'], false);
check('absurd lifetime energy rejected', $v($ok + ['lifetimeEnergy' => 1e9])['ok'], false);
check('power 500 kW rejected', $v($ok + ['power' => 500])['ok'], false);
check('non-numeric energy rejected', $v($ok + ['sessionEnergy' => 'abc'])['ok'], false);
check('boolean energy rejected', $v($ok + ['sessionEnergy' => true])['ok'], false);
check('status is lower-cased', $v($ok + ['status' => 'Awaiting_Authorization'])['row']['chargerStatus'], 'awaiting_authorization');
check('status with markup is dropped', $v($ok + ['status' => '<script>'])['row']['chargerStatus'], null);

$cars = ['2' => ['soc' => 53, 'odometer' => '58929', 'cable' => 'off', 'carTs' => '2026-10-31T05:19:03+00:00', 'evil' => 'x'],
         '3' => ['soc' => 150, 'odometer' => -1, 'cable' => 'connected', 'carTs' => 'unknown']];
$r = $v($ok + ['cars' => $cars]); $c = json_decode($r['row']['cars'], true);
checkLoose('cars: values cleaned', $c['2'], ['soc' => 53, 'odometer' => 58929, 'cable' => 'off', 'carTs' => '2026-10-31T05:19:03Z']);
checkLoose('cars: out-of-range / unparseable values become null, unknown keys are dropped', $c['3'], ['soc' => null, 'odometer' => null, 'cable' => 'connected', 'carTs' => null]);
check('cars: bad key rejected', $v($ok + ['cars' => ['abc' => ['soc' => 5]]])['ok'], false);
check('cars: vehicleId 0 rejected', $v($ok + ['cars' => ['0' => ['soc' => 5]]])['ok'], false);
check('cars: not an object rejected', $v($ok + ['cars' => 'x'])['ok'], false);
check('cars: too many rejected', $v($ok + ['cars' => array_fill(1, 25, ['soc' => 1])])['ok'], false);
check('cars: empty -> stored as NULL', $v($ok + ['cars' => []])['row']['cars'], null);
check('unrelated keys are ignored', array_keys($v($ok + ['password' => 'x'])['row']), ['eventAt', 'event', 'chargerStatus', 'sessionEnergy', 'lifetimeEnergy', 'power', 'cars']);

echo "ChargerSessions::build() on real data\n";
$fx = json_decode(file_get_contents(__DIR__ . '/fixtures/charger_events_sample.json'), true);
foreach ($fx as &$e) $e['cars'] = null; unset($e);
$s = ChargerSessions::build($fx);
check('three sessions', count($s), 3);
check('s1: plug-in happened before the data starts', $s[0]['pluggedAt'], null);
check('s1: energy', $s[0]['energyKwh'], 22.142, 1e-3);
check('s2: plugged / start / end / unplugged', [$s[1]['pluggedAt'], $s[1]['chargeStartAt'], $s[1]['chargeEndAt'], $s[1]['unpluggedAt']],
    ['2026-10-26T19:25:45Z', '2026-10-26T23:25:42Z', '2026-10-27T04:50:42Z', '2026-10-27T10:04:36Z']);
check('s2: energy', $s[1]['energyKwh'], 56.533, 1e-3);
check('s3: energy', $s[2]['energyKwh'], 36.449, 1e-3);
foreach ([[0, 10.45], [1, 10.44], [2, 10.54]] as [$i, $kw]) check("avg power s" . ($i + 1) . " is ~$kw kW", $s[$i]['avgPowerKw'], $kw, 0.02);
check('hours charged s2', $s[1]['chargingHours'], 5.417, 1e-3);
check('none is open', array_map(fn($x) => $x['open'], $s), [false, false, false]);
check('s1 on-unplug reading is the stale repeat of its own energy, not used twice', $s[0]['energyKwh'], 22.142, 1e-3);

echo "ChargerSessions::build() edge cases\n";
$ev = fn(string $t, string $type, array $x = []) => $x + ['eventAt' => $t, 'event' => $type, 'sessionEnergy' => null, 'lifetimeEnergy' => null, 'power' => null, 'chargerStatus' => null, 'cars' => null];
$s = ChargerSessions::build([$ev('2026-10-01T20:00:00Z', 'plugged'), $ev('2026-10-02T20:00:00Z', 'plugged'), $ev('2026-10-02T22:00:00Z', 'unplugged')]);
check('a new plug-in closes the previous session that never saw an unplug', [count($s), $s[0]['unpluggedAt'], $s[1]['unpluggedAt']], [2, null, '2026-10-02T22:00:00Z']);

$s = ChargerSessions::build([$ev('2026-10-01T22:00:00Z', 'charge_start'), $ev('2026-10-02T01:00:00Z', 'charge_end', ['sessionEnergy' => 30.0])]);
check('charging without a seen plug-in is still a session (open)', [count($s), $s[0]['pluggedAt'], $s[0]['open'], $s[0]['energyKwh']], [1, null, true, 30.0]);

$s = ChargerSessions::build([$ev('2026-10-01T20:00:00Z', 'plugged'), $ev('2026-10-01T21:00:00Z', 'charge_start'), $ev('2026-10-01T22:00:00Z', 'charge_end', ['sessionEnergy' => 10.0]),
    $ev('2026-10-02T01:00:00Z', 'charge_start'), $ev('2026-10-02T03:00:00Z', 'charge_end', ['sessionEnergy' => 20.0]), $ev('2026-10-02T08:00:00Z', 'unplugged')]);
check('two charge segments add up', [$s[0]['segments'], $s[0]['energyKwh'], $s[0]['chargingHours'], $s[0]['avgPowerKw']], [2, 30.0, 3.0, 10.0]);

$s = ChargerSessions::build([$ev('2026-10-01T20:00:00Z', 'plugged'), $ev('2026-10-01T21:00:00Z', 'charge_start'),
    $ev('2026-10-02T08:00:00Z', 'unplugged', ['sessionEnergy' => 17.5])]);
check('missed charge_end: on-unplug counter is used', $s[0]['energyKwh'], 17.5);

$s = ChargerSessions::build([$ev('2026-10-01T20:00:00Z', 'plugged'), $ev('2026-10-01T21:00:00Z', 'charge_start'), $ev('2026-10-01T23:00:00Z', 'charge_end', ['sessionEnergy' => 22.142]), $ev('2026-10-02T08:00:00Z', 'unplugged'),
    $ev('2026-10-03T20:00:00Z', 'plugged'), $ev('2026-10-03T21:00:00Z', 'charge_start'), $ev('2026-10-04T08:00:00Z', 'unplugged', ['sessionEnergy' => 22.142])]);
check('missed charge_end: the previous session\'s stale value is NOT reused', $s[1]['energyKwh'], null);

$s = ChargerSessions::build([$ev('2026-10-01T20:00:00Z', 'plugged'), $ev('2026-10-02T08:00:00Z', 'unplugged', ['sessionEnergy' => 5.0])]);
check('plug + unplug without any charging has no energy', $s[0]['energyKwh'], null);

$s = ChargerSessions::build([$ev('2026-10-02T08:00:00Z', 'unplugged'), $ev('2026-10-01T20:00:00Z', 'plugged'), $ev('2026-10-01T21:00:00Z', 'charge_start'), $ev('2026-10-02T00:00:00Z', 'charge_end', ['sessionEnergy' => 9.0])]);
check('input order does not matter', [count($s), $s[0]['energyKwh'], $s[0]['unpluggedAt']], [1, 9.0, '2026-10-02T08:00:00Z']);

$s = ChargerSessions::build([$ev('2026-10-01T20:00:00Z', 'plugged'), $ev('2026-10-01T21:00:00Z', 'charge_start'), $ev('2026-10-01T22:00:00Z', 'charge_end', ['sessionEnergy' => 5.0]), $ev('2026-10-01T22:00:00Z', 'unplugged')]);
check('events in the same second keep their logical order', [count($s), $s[0]['unpluggedAt'], $s[0]['energyKwh']], [1, '2026-10-01T22:00:00Z', 5.0]);

$cs = ['2' => ['soc' => 40.0, 'odometer' => 1000.0, 'cable' => 'on', 'carTs' => null]];
$ce = ['2' => ['soc' => 70.0, 'odometer' => 1000.0, 'cable' => 'on', 'carTs' => null]];
$s = ChargerSessions::build([$ev('2026-10-01T20:00:00Z', 'plugged', ['cars' => $cs]), $ev('2026-10-01T21:00:00Z', 'charge_start'), $ev('2026-10-01T23:00:00Z', 'charge_end', ['sessionEnergy' => 20.0, 'cars' => $ce])]);
check('car snapshots are kept per stage', [$s[0]['cars']['plugged']['2']['soc'], $s[0]['cars']['charge_end']['2']['soc'], $s[0]['cars']['unplugged']], [40.0, 70.0, null]);
check('empty input -> no sessions', ChargerSessions::build([]), []);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
