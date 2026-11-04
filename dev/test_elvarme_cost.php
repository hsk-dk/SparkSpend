<?php
/**
 * Tests for includes/ElvarmeCost.php, the server-side electricity-heating cost model.
 *
 *  1. Unit cases (hand-computed).
 *  2. Parity: random cases are also run through the JavaScript implementations
 *     (includes/app.js + includes/pricing.js, via dev/parity_elvarme.js) and the
 *     results must agree. Skipped with a notice when Node is not installed.
 *
 * Run:  php dev/test_elvarme_cost.php
 */
require __DIR__ . '/../includes/ElvarmeCost.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
function close($a, $b, float $eps = 1e-9): bool {
    if ($a === null || $b === null) return $a === $b;
    return abs($a - $b) <= $eps * max(1.0, abs($a), abs($b));
}

echo "cost(): flat rates\n";
$base = ['highRate' => 3.0, 'lowRate' => 1.0, 'highKwhPerMonth' => 100.0];
$r = ElvarmeCost::cost($base + ['vpKwh' => 60, 'restKwh' => 20]);
check('below the allowance everything is HIGH', close($r['total'], 80 * 3.0));
check('cost is split by kWh share', close($r['vpCost'], 60 * 3.0) && close($r['restCost'], 20 * 3.0));
$r = ElvarmeCost::cost($base + ['vpKwh' => 150, 'restKwh' => 50]);
check('above the allowance: 100 kWh HIGH, rest LOW', close($r['total'], 100 * 3.0 + 100 * 1.0));
check('split by share (75/25)', close($r['vpCost'], 400 * 0.75) && close($r['restCost'], 400 * 0.25));
$r = ElvarmeCost::cost($base + ['vpKwh' => 10, 'restKwh' => 10, 'evKwh' => 40]);
check('EV is always LOW', close($r['evCost'], 40 * 1.0) && close($r['total'], 20 * 3.0 + 40 * 1.0));
$r = ElvarmeCost::cost($base + ['vpKwh' => -5, 'restKwh' => 20]);
check('negative kWh count as 0', close($r['vpCost'], 0.0) && close($r['restCost'], 20 * 3.0));
$r = ElvarmeCost::cost($base);
check('no consumption -> 0, no NaN', $r['total'] === 0.0 && !is_nan($r['vpCost']));

echo "cost(): per-component rates\n";
$r = ElvarmeCost::cost($base + ['vpKwh' => 100, 'restKwh' => 100, 'vpRates' => ['high' => 4.0, 'low' => 2.0]]);
// f = 100/200 = 0.5 -> VP mix 3.0, Rest falls back to flat mix 2.0
check('VP uses its own mix, Rest the flat rates', close($r['vpCost'], 100 * 3.0) && close($r['restCost'], 100 * 2.0));
$flat = ElvarmeCost::cost($base + ['vpKwh' => 70, 'restKwh' => 90]);
$eq   = ElvarmeCost::cost($base + ['vpKwh' => 70, 'restKwh' => 90,
    'vpRates' => ['high' => 3.0, 'low' => 1.0], 'restRates' => ['high' => 3.0, 'low' => 1.0]]);
check('equal rates give exactly the flat model', close($flat['total'], $eq['total']) && close($flat['vpCost'], $eq['vpCost']));

echo "priceByHour() / weightedRate()\n";
$m = ElvarmeCost::priceByHour([
    ['date' => '2026-10-25', 'hour' => 2, 'kr_kwh' => 1.0], ['date' => '2026-10-25', 'hour' => 2, 'kr_kwh' => 3.0],
    ['date' => '2026-10-25', 'hour' => 3, 'kr_kwh' => 2.0], ['date' => '2026-10-25', 'hour' => 4, 'kr_kwh' => 'x'], null,
]);
check('key is "YYYY-MM-DD HH"', ($m['2026-10-25 03'] ?? null) === 2.0);
check('the repeated DST hour is averaged', ($m['2026-10-25 02'] ?? null) === 2.0);
check('non-numeric prices are skipped', !array_key_exists('2026-10-25 04', $m));
$prices = ['h0' => 1.0, 'h1' => 2.0, 'h2' => 3.0];
check('sum(kWh*price)/sum(kWh)', close(ElvarmeCost::weightedRate(['h0', 'h1', 'h2'], [1, 1, 2], $prices), 9 / 4));
check('hours without a price are left out of both sums', close(ElvarmeCost::weightedRate(['h0', 'h1', 'h9'], [1, 1, 100], $prices), 1.5));
check('no usable hour -> null', ElvarmeCost::weightedRate(['h9'], [1], $prices) === null && ElvarmeCost::weightedRate([], [], $prices) === null);

// ── Parity with the JavaScript implementation ────────────────────────────────
echo "parity with JS (app.js / pricing.js)\n";
$node = trim((string) @shell_exec(stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
if ($node === '') {
    echo "  skip node not installed\n";
} else {
    mt_srand(20260101);
    $rnd  = fn(float $a, float $b) => $a + mt_rand() / mt_getrandmax() * ($b - $a);
    $pick = fn(array $xs) => $xs[mt_rand(0, count($xs) - 1)];
    $cases = ['cost' => [], 'weight' => []];

    for ($i = 0; $i < 400; $i++) {
        $c = [
            'highRate' => $rnd(0.5, 6), 'lowRate' => $rnd(0.2, 3), 'highKwhPerMonth' => $pick([0, 100, 333.33, 1000]),
            'vpKwh' => $pick([0, -3, $rnd(0, 800)]), 'restKwh' => $pick([0, -1, $rnd(0, 600)]), 'evKwh' => $pick([0, $rnd(0, 400)]),
        ];
        if (mt_rand(0, 2) === 0) $c['vpRates']   = ['high' => $rnd(0.5, 6), 'low' => $rnd(0.2, 3)];
        if (mt_rand(0, 2) === 0) $c['restRates'] = ['high' => $rnd(0.5, 6), 'low' => $rnd(0.2, 3)];
        if (mt_rand(0, 9) === 0) unset($c['evKwh']);
        $cases['cost'][] = $c;
    }
    for ($i = 0; $i < 150; $i++) {
        $hours = []; $recsH = []; $recsL = [];
        $nH = mt_rand(0, 60);
        for ($h = 0; $h < $nH; $h++) {
            $d = sprintf('2026-10-%02d', 1 + intdiv($h, 24)); $hr = $h % 24;
            $hours[] = sprintf('%s %02d', $d, $hr);
            if (mt_rand(0, 7) !== 0) { $recsH[] = ['date' => $d, 'hour' => $hr, 'kr_kwh' => $pick([$rnd(0.1, 4), null, '2.5', 'x'])]; }
            if (mt_rand(0, 7) !== 0) { $recsL[] = ['date' => $d, 'hour' => $hr, 'kr_kwh' => $rnd(0.1, 4)]; }
            if (mt_rand(0, 15) === 0) $recsH[] = ['date' => $d, 'hour' => $hr, 'kr_kwh' => $rnd(0.1, 4)];   // DST duplicate
        }
        $vp = $rest = [];
        for ($h = 0; $h < $nH; $h++) {
            $vp[]   = $pick([0, null, $rnd(0, 3), '1.5', -1]);
            $rest[] = $pick([0, $rnd(0, 2)]);
        }
        $cons = ['hours' => $hours, 'vp' => $vp, 'rest' => $rest];
        if (mt_rand(0, 19) === 0) unset($cons['rest']);
        $cases['weight'][] = ['consumption' => $cons, 'high' => $recsH, 'low' => $recsL];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'elv');
    file_put_contents($tmp, json_encode($cases));
    $raw = shell_exec('node ' . escapeshellarg(__DIR__ . '/parity_elvarme.js') . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    $js = json_decode((string) $raw, true);
    if (!is_array($js)) {
        check('node ran the JS implementation', false, substr((string) $raw, 0, 200));
    } else {
        $bad = 0; $first = '';
        foreach ($cases['cost'] as $i => $c) {
            $php = ElvarmeCost::cost($c);
            foreach (['vpCost', 'restCost', 'evCost', 'total'] as $k) {
                if (!close($php[$k], $js['cost'][$i][$k])) { $bad++; $first = $first ?: "case $i $k php={$php[$k]} js={$js['cost'][$i][$k]}"; }
            }
        }
        check(count($cases['cost']) . ' random cost() cases identical to JS', $bad === 0, $first);

        $bad = 0; $first = '';
        foreach ($cases['weight'] as $i => $c) {
            $pm = ElvarmeCost::priceByHour($c['high']); $jm = $js['priceByHour'][$i] ?? [];
            if (count($pm) !== count($jm)) { $bad++; $first = $first ?: "priceByHour size case $i"; }
            foreach ($pm as $k => $v) if (!isset($jm[$k]) || !close($v, $jm[$k])) { $bad++; $first = $first ?: "priceByHour $k case $i"; }

            $pw = ElvarmeCost::weightedRates($c['consumption'], $c['high'], $c['low']);
            $jw = $js['weightedRates'][$i];
            if (($pw === null) !== ($jw === null)) { $bad++; $first = $first ?: "weightedRates null-ness case $i"; continue; }
            if ($pw === null) continue;
            foreach (['vp', 'rest'] as $part) {
                if (($pw[$part] === null) !== (($jw[$part] ?? null) === null)) { $bad++; $first = $first ?: "weightedRates $part null-ness case $i"; continue; }
                if ($pw[$part] === null) continue;
                foreach (['high', 'low'] as $t) if (!close($pw[$part][$t], $jw[$part][$t])) { $bad++; $first = $first ?: "weightedRates $part $t case $i"; }
            }
        }
        check(count($cases['weight']) . ' random weighting cases identical to JS', $bad === 0, $first);
    }
}

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
