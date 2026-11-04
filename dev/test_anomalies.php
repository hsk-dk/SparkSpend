<?php
/**
 * Tests for includes/AnomalyDetector.php on in-memory databases, for a fixed date and clock.
 *   Run: php dev/test_anomalies.php
 *
 * Scenario: one reading per day on both meters (house 20 kWh/day, heat pump 10 kWh/day) for
 * the same period last year; this month's daily use is scaled per test.
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');   // defaults: no weather lookup
require __DIR__ . '/../includes/configuration.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}

const TODAY = '2026-11-20';
$today = new DateTimeImmutable(TODAY);
$now   = strtotime(TODAY . ' 12:00:00');

function makeDbs(float $husFactor, float $hpFactor, ?string $lastReadingDay = TODAY, bool $thisMonthData = true): array {
    $root = dirname(__DIR__);
    $charges  = new PDO('sqlite::memory:'); $charges->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $powerlog = new PDO('sqlite::memory:'); $powerlog->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ([$charges, $powerlog] as $db) $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    MigrationRunner::run($charges,  "$root/migrations/charges");
    MigrationRunner::run($powerlog, "$root/migrations/powerlog");

    $ins = ['powerloghus' => $powerlog->prepare('INSERT INTO powerloghus (logdate, kwh) VALUES (?, ?)'),
            'powerlogjord' => $powerlog->prepare('INSERT INTO powerlogjord (logdate, kwh) VALUES (?, ?)')];
    $hus = 1000.0; $hp = 500.0;
    for ($d = new DateTimeImmutable('2025-10-25'); $d <= new DateTimeImmutable(TODAY); $d = $d->modify('+1 day')) {   // calendar days (DST-safe)
        $day = $d->format('Y-m-d');
        $thisMonth = $day >= '2026-11-01';
        if ($thisMonth && !$thisMonthData) continue;
        if ($lastReadingDay !== null && $day > $lastReadingDay) break;
        $hus += 20 * ($thisMonth ? $husFactor : 1);
        $hp  += 10 * ($thisMonth ? $hpFactor  : 1);
        $ins['powerloghus']->execute(["$day 12:00:00", $hus]);
        $ins['powerlogjord']->execute(["$day 12:00:00", $hp]);
    }
    return [$charges, $powerlog];
}
function detect(array $dbs, DateTimeImmutable $today, int $now): array { return AnomalyDetector::detect($dbs[0], $dbs[1], $today, $now); }
function find(array $r, string $category, ?string $direction = null): ?array {
    foreach ($r['anomalies'] as $a) if ($a['category'] === $category && ($direction === null || $a['direction'] === $direction)) return $a;
    return null;
}

echo "too early in the month\n";
$r = AnomalyDetector::detect(...makeDbs(1, 1), ...[new DateTimeImmutable('2026-11-04'), $now]);
check('before day 5: no alerts, flagged as insufficient data', $r['insufficient_data'] === true && $r['anomalies'] === []);

echo "normal use\n";
$r = detect(makeDbs(1.0, 1.0), $today, $now);
check('same use as last year: nothing raised', !$r['insufficient_data'] && $r['anomalies'] === [], json_encode($r['anomalies']));

echo "unusually HIGH use\n";
$r = detect(makeDbs(1.0, 1.7), $today, $now);
$a = find($r, 'hp');
check('heat pump +70% -> critical, direction high', $a && $a['severity'] === 'critical' && $a['direction'] === 'high' && $a['pct_above'] === 70, json_encode($a));
check('the house meter is not flagged for that', find($r, 'hus') === null);
$r = detect(makeDbs(1.0, 1.35), $today, $now);
check('heat pump +35% -> warning', (find($r, 'hp')['severity'] ?? '') === 'warning');
$r = detect(makeDbs(1.0, 1.15), $today, $now);
check('heat pump +15% -> info', (find($r, 'hp')['severity'] ?? '') === 'info');
check('heat pump +5% -> below the threshold, nothing', detect(makeDbs(1.0, 1.05), $today, $now)['anomalies'] === []);

echo "unusually LOW use (house and heat pump only)\n";
$r = detect(makeDbs(0.4, 1.0), $today, $now);
$a = find($r, 'hus');
check('house -60% -> warning, direction low', $a && $a['severity'] === 'warning' && $a['direction'] === 'low' && $a['headline'] === '60% under baseline', json_encode($a));
$r = detect(makeDbs(1.0, 0.65), $today, $now);
check('heat pump -35% -> info (mild)', (find($r, 'hp')['severity'] ?? '') === 'info' && (find($r, 'hp')['direction'] ?? '') === 'low');
check('heat pump -20% -> nothing', detect(makeDbs(1.0, 0.8), $today, $now)['anomalies'] === []);

echo "dead and stale meters\n";
$r = detect(makeDbs(1.0, 1.0, null, false), $today, $now);   // no readings at all this month
$a = find($r, 'hp', 'none');
check('no consumption this month -> critical "none" (dead meter)', $a && $a['severity'] === 'critical' && $a['headline'] === 'Intet forbrug registreret', json_encode($r['anomalies']));
$r = detect(makeDbs(1.0, 1.0, '2026-11-17'), $today, $now);   // last reading 3 days before "now"
$a = find($r, 'hus', 'stale');
check('latest reading older than 36 h -> stale warning', $a && $a['severity'] === 'warning' && $a['direction'] === 'stale', json_encode($r['anomalies']));
$r = detect(makeDbs(1.0, 1.0, '2026-11-20'), $today, strtotime(TODAY . ' 23:00:00'));
check('a reading from today at 12:00 is not stale at 23:00', find($r, 'hus', 'stale') === null);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
