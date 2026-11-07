<?php
/**
 * DeviceEnergy: validation of what Home Assistant posts, and the storage (daily / monthly sums).
 * Run:  php dev/test_device_energy.php
 */
require __DIR__ . '/../includes/DeviceEnergy.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
$today = '2026-11-07';
$ok  = fn(array $in) => DeviceEnergy::validate($in, $today);

echo "validate()\n";
$r = $ok(['date' => '2026-11-06', 'devices' => ['varme_skur' => 12.34]]);
check('one day is accepted', $r['ok'] && $r['rows'] === [['day' => '2026-11-06', 'device' => 'varme_skur', 'kwh' => 12.34]]);
$r = $ok(['days' => [['date' => '2026-11-05', 'devices' => ['varme_skur' => '3.5', 'fryser' => 0]], ['date' => '2026-11-06', 'devices' => ['varme_skur' => 4]]]]);
check('a backfill with several days and devices; numeric strings are accepted', $r['ok'] && count($r['rows']) === 3 && $r['rows'][0]['kwh'] === 3.5);
check('zero is a valid value', $ok(['date' => '2026-11-06', 'devices' => ['varme_skur' => 0]])['ok']);
check('today itself is accepted', $ok(['date' => $today, 'devices' => ['varme_skur' => 1]])['ok']);
check('a future date is refused', !$ok(['date' => '2026-11-08', 'devices' => ['varme_skur' => 1]])['ok']);
check('an impossible date is refused', !$ok(['date' => '2026-02-30', 'devices' => ['varme_skur' => 1]])['ok']);
check('a date in the wrong format is refused', !$ok(['date' => '06-11-2026', 'devices' => ['varme_skur' => 1]])['ok']);
check('a negative value is refused', !$ok(['date' => '2026-11-06', 'devices' => ['varme_skur' => -1]])['ok']);
check('an absurd value (kWh/day) is refused', !$ok(['date' => '2026-11-06', 'devices' => ['varme_skur' => 9999]])['ok']);
check('text instead of a number is refused', !$ok(['date' => '2026-11-06', 'devices' => ['varme_skur' => 'meget']])['ok']);
check('NAN / INF are refused', !$ok(['date' => '2026-11-06', 'devices' => ['varme_skur' => NAN]])['ok'] && !$ok(['date' => '2026-11-06', 'devices' => ['varme_skur' => INF]])['ok']);
check('a device key with odd characters is refused', !$ok(['date' => '2026-11-06', 'devices' => ['Varme Skur; DROP' => 1]])['ok']);
check('a device list instead of an object is refused', !$ok(['date' => '2026-11-06', 'devices' => [1, 2]])['ok']);
check('no devices is refused', !$ok(['date' => '2026-11-06', 'devices' => []])['ok']);
check('an empty days list is refused', !$ok(['days' => []])['ok']);
$many = []; for ($i = 0; $i < DeviceEnergy::MAX_DAYS + 1; $i++) $many[] = ['date' => '2026-01-01', 'devices' => ['x' => 1]];
check('more than ' . DeviceEnergy::MAX_DAYS . ' days in one request is refused', !$ok(['days' => $many])['ok']);
check('one bad day refuses the whole request (nothing half-stored)', !$ok(['days' => [['date' => '2026-11-05', 'devices' => ['a' => 1]], ['date' => 'x', 'devices' => ['a' => 1]]]])['ok']);

echo "storage\n";
$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mig = require __DIR__ . '/../migrations/powerlog/005_add_device_energy.php';
foreach ($mig['up'] as $sql) $db->exec($sql);
check('before any data the queries are empty', DeviceEnergy::daily($db, 'varme_skur', '2026-01-01', '2026-12-31') === []);
DeviceEnergy::store($db, [['day' => '2026-01-01', 'device' => 'varme_skur', 'kwh' => 5.0], ['day' => '2026-01-02', 'device' => 'varme_skur', 'kwh' => 6.5], ['day' => '2026-02-01', 'device' => 'varme_skur', 'kwh' => 1.0], ['day' => '2026-01-01', 'device' => 'fryser', 'kwh' => 0.3]]);
check('daily sums per device', DeviceEnergy::daily($db, 'varme_skur', '2026-01-01', '2026-01-31') === ['2026-01-01' => 5.0, '2026-01-02' => 6.5]);
check('monthly sums per device', DeviceEnergy::monthly($db, 'varme_skur', '2026-01-01', '2026-12-31') === ['2026-01' => 11.5, '2026-02' => 1.0]);
DeviceEnergy::store($db, [['day' => '2026-01-01', 'device' => 'varme_skur', 'kwh' => 7.0]]);
check('sending a day again replaces it (idempotent)', DeviceEnergy::daily($db, 'varme_skur', '2026-01-01', '2026-01-01') === ['2026-01-01' => 7.0]);
check('another device is not mixed in', DeviceEnergy::daily($db, 'fryser', '2026-01-01', '2026-12-31') === ['2026-01-01' => 0.3]);
$bare = new PDO('sqlite::memory:');
check('a database without the table (not migrated) gives empty, not an error', DeviceEnergy::daily($bare, 'varme_skur', '2026-01-01', '2026-12-31') === []);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
