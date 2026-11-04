<?php
/**
 * Tests for includes/ElvarmeCost.php, the server-side electricity-heating cost model.
 *
 * The model used to exist in JavaScript too (app.js elvarmeCost + pricing.js). Before
 * those copies were removed, 550 random cases were checked to be identical between the
 * two; the JS test cases live on here.
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

echo "weightedRates()\n";
$cons = ['hours' => ['2026-10-01 00', '2026-10-01 01'], 'vp' => [3, 1], 'rest' => [1, 3]];
$rec  = fn($a, $b) => [['date' => '2026-10-01', 'hour' => 0, 'kr_kwh' => $a], ['date' => '2026-10-01', 'hour' => 1, 'kr_kwh' => $b]];
$wr = ElvarmeCost::weightedRates($cons, $rec(1, 2), $rec(0.5, 1));
check('vp: high', close($wr['vp']['high'], (3 * 1 + 1 * 2) / 4));
check('vp: low',  close($wr['vp']['low'],  (3 * 0.5 + 1 * 1) / 4));
check('rest is weighted by its own consumption', close($wr['rest']['high'], (1 * 1 + 3 * 2) / 4));
check('no consumption series -> null', ElvarmeCost::weightedRates([], $rec(1, 2), $rec(1, 2)) === null);
check('prices missing -> null', ElvarmeCost::weightedRates($cons, [], []) === null);
check('the weighted price differs from the plain average when use is skewed',
    !close(ElvarmeCost::weightedRate(['h0', 'h1', 'h2'], [1, 1, 2], $prices), 2.0));
check('night-time use is priced at night prices', ElvarmeCost::weightedRate(['h0', 'h2'], [9, 1], $prices) < 2.0);
check('hours without consumption are ignored', close(ElvarmeCost::weightedRate(['h0', 'h1'], [0, 4], $prices), 2.0));

echo "cost(): property checks\n";
$b = ['vpKwh' => 200, 'restKwh' => 300, 'evKwh' => 40, 'highRate' => 3.0, 'lowRate' => 2.0, 'highKwhPerMonth' => 333];
$legacy = ElvarmeCost::cost($b);
$hk = 333; $vr = 500; $lowK = $vr - $hk;
check('without per-component rates: the flat model', close($legacy['total'], $hk * 3 + $lowK * 2 + 40 * 2)
    && close($legacy['vpCost'] + $legacy['restCost'], $hk * 3 + $lowK * 2));
$same = ElvarmeCost::cost($b + ['vpRates' => ['high' => 3, 'low' => 2], 'restRates' => ['high' => 3, 'low' => 2]]);
check('equal per-component rates == flat model', close($same['vpCost'], $legacy['vpCost']) && close($same['restCost'], $legacy['restCost']) && close($same['total'], $legacy['total']));
$worst = 0.0; $seed = 12345;
$rnd = function () use (&$seed) { $seed = ($seed * 1103515245 + 12345) % 2147483648; return $seed / 2147483648; };
for ($i = 0; $i < 20000; $i++) {
    $p = ['vpKwh' => $rnd() * 800, 'restKwh' => $rnd() * 800, 'evKwh' => $rnd() * 200, 'highRate' => 1 + $rnd() * 3, 'lowRate' => $rnd() * 3, 'highKwhPerMonth' => $rnd() * 700];
    $x = ElvarmeCost::cost($p);
    $y = ElvarmeCost::cost($p + ['vpRates' => ['high' => $p['highRate'], 'low' => $p['lowRate']], 'restRates' => ['high' => $p['highRate'], 'low' => $p['lowRate']]]);
    $worst = max($worst, abs($x['total'] - $y['total']), abs($x['vpCost'] - $y['vpCost']), abs($x['restCost'] - $y['restCost']));
}
check('20000 random inputs: equal rates give the flat result (max diff ' . sprintf('%.1e', $worst) . ')', $worst < 1e-9);
$cheapVp = ElvarmeCost::cost($b + ['vpRates' => ['high' => 2.5, 'low' => 1.5], 'restRates' => ['high' => 3.5, 'low' => 2.5]]);
check('cheaper VP hours -> lower VP cost, higher Rest cost', $cheapVp['vpCost'] < $legacy['vpCost'] && $cheapVp['restCost'] > $legacy['restCost']);
$f = $hk / $vr;
check('allowance shared pro rata', close($cheapVp['vpCost'], 200 * ($f * 2.5 + (1 - $f) * 1.5)) && close($cheapVp['restCost'], 300 * ($f * 3.5 + (1 - $f) * 2.5)));
check('total = VP + Rest + EV', close($cheapVp['total'], $cheapVp['vpCost'] + $cheapVp['restCost'] + $cheapVp['evCost']));
check('EV cost is unaffected by component rates', close($cheapVp['evCost'], $legacy['evCost']));
$onlyVp = ElvarmeCost::cost($b + ['vpRates' => ['high' => 2.5, 'low' => 1.5]]);
check('only vpRates given: Rest falls back to the flat rates', close($onlyVp['restCost'], $f * 300 * 3 + (1 - $f) * 300 * 2));
check('zero consumption with component rates -> zero cost, no NaN',
    ElvarmeCost::cost(['highRate' => 3, 'lowRate' => 2, 'highKwhPerMonth' => 333, 'vpRates' => ['high' => 1, 'low' => 1]])['total'] === 0.0);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
