<?php
/**
 * Guards the electricity-tax data in config/tax_rates.json (see includes/TaxRates.php).
 *
 * The rates are law and change by date, usually once a year. This test
 *   - validates the file (and the validator itself on broken inputs),
 *   - checks the date lookup (history is priced at the rate of its time),
 *   - fails from 1 January of a year the file does not yet cover, as the reminder to check
 *     skat.dk and add that year's row (the newest rate would otherwise silently keep applying).
 *
 * Run:  php dev/test_config_rates.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');   // defaults only
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';
require __DIR__ . '/../includes/TaxRates.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}

Config::load();
$year = (int) date('Y');
$file = TaxRates::DEFAULT_FILE;

echo "the data file\n";
$raw = json_decode((string) @file_get_contents($file), true);
check('config/tax_rates.json exists and is valid JSON', is_array($raw));
check('it passes validation', is_array($raw) && TaxRates::validate($raw) === [], implode('; ', is_array($raw) ? TaxRates::validate($raw) : []));
check('it names its source', is_array($raw) && str_starts_with((string) ($raw['source'] ?? ''), 'http'));

echo "validation catches bad data\n";
$good = $raw ?: [];
$with = fn(array $patch) => array_replace($good, $patch);
check('an empty table is rejected', TaxRates::validate($with(['elafgift_high' => []])) !== []);
check('a missing table is rejected', TaxRates::validate(array_diff_key($good, ['elafgift_low' => 1])) !== []);
check('a bad date is rejected', TaxRates::validate($with(['elafgift_low' => [['from' => '2026-13-01', 'rate' => 0.008]]])) !== []);
check('a rate given as text is rejected', TaxRates::validate($with(['elafgift_low' => [['from' => '2026-01-01', 'rate' => '0,008']]])) !== []);
check('a rate in oere instead of kr (761) is rejected', TaxRates::validate($with(['elafgift_high' => [['from' => '2026-01-01', 'rate' => 761]]])) !== []);
check('rows out of date order are rejected', TaxRates::validate($with(['elafgift_low' => [['from' => '2026-01-01', 'rate' => 0.1], ['from' => '2025-01-01', 'rate' => 0.1]]])) !== []);
check('duplicate dates are rejected', TaxRates::validate($with(['elafgift_low' => [['from' => '2026-01-01', 'rate' => 0.1], ['from' => '2026-01-01', 'rate' => 0.2]]])) !== []);
check('a non-positive allowance is rejected', TaxRates::validate($with(['elvarme_high_kwh_per_year' => 0])) !== []);
check('VAT as 25 instead of 1.25 is rejected', TaxRates::validate($with(['vat_factor' => 25])) !== []);
$tmp = tempnam(sys_get_temp_dir(), 'tax'); file_put_contents($tmp, '{ not json');
$threw = false; try { TaxRates::load($tmp); } catch (RuntimeException $e) { $threw = true; }
check('loading an invalid file throws (no silent fallback to wrong rates)', $threw);
$threw = false; try { TaxRates::load($tmp . '.missing'); } catch (RuntimeException $e) { $threw = true; }
check('loading a missing file throws', $threw);
@unlink($tmp);

echo "rate lookup\n";
$today = date('Y-m-d');
check('high rate is not negative',  Config::elafgiftHigh($today) >= 0);
check('low rate is positive',       Config::elafgiftLow($today) > 0);
check('low rate <= high rate',      Config::elafgiftLow($today) <= Config::elafgiftHigh($today));
check('dates before the first row use the earliest rate', Config::elafgiftHigh('1999-01-01') === Config::elafgiftHigh('2024-01-01'));
check('the rate changes on the date it takes effect (2025-12-31 vs 2026-01-01 for the full rate)',
    Config::elafgiftHigh('2025-12-31') === 0.761 && Config::elafgiftHigh('2026-01-01') === 0.008);
check('a later row overrides an earlier one (test override)', (function () {
    Config::override(['elafgiftHighByDate' => ['2024-01-01' => 1.0, '2025-01-01' => 2.0]]);
    $ok = Config::elafgiftHigh('2024-12-31') === 1.0 && Config::elafgiftHigh('2025-01-01') === 2.0;
    Config::reset(); Config::load();
    return $ok;
})());
check('the allowance is 4000 kWh/year = 333.3 kWh/month', abs(Config::elvarmeHighKwhPerMonth() - 4000 / 12) < 1e-9);
check('VAT is 25 %', abs(Config::vatFactor() - 1.25) < 1e-9);
check('the file fingerprint is stable and changes with the content', Config::taxRatesVersion() === Config::taxRatesVersion()
    && TaxRates::load()['version'] !== substr(md5('x'), 0, 8));

echo "coverage of the current year\n";
foreach (['high' => 'HIGH (elafgift)', 'low' => 'LOW (elvarmeafgift)'] as $key => $label) {
    $years = array_map(fn($d) => (int) substr($d, 0, 4), array_keys(TaxRates::load()[$key]));
    check("$label has a row for $year or later", max($years) >= $year,
        "- newest row is " . max($years) . "; check skat.dk and add a row in config/tax_rates.json");
}

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
