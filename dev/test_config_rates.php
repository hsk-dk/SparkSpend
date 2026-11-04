<?php
/**
 * Guards the date-versioned electricity-tax tables in includes/Config.php.
 *
 * The rates are edited by hand once a year. If nobody adds next year's row, the
 * newest known rate silently keeps applying (see Config::rateForDate), which is
 * only correct if the rate really did not change. This test fails from 1 January
 * of a year the tables do not yet cover, as a reminder to check skat.dk.
 *
 * Run:  php dev/test_config_rates.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');   // defaults only
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}

Config::load();
$year = (int) date('Y');

echo "rate lookup\n";
check('high rate is positive',  Config::elafgiftHigh(date('Y-m-d')) > 0);
check('low rate is positive',   Config::elafgiftLow(date('Y-m-d')) > 0);
check('low rate <= high rate',  Config::elafgiftLow(date('Y-m-d')) <= Config::elafgiftHigh(date('Y-m-d')));
check('dates before the first row fall back to the earliest rate', Config::elafgiftHigh('1999-01-01') === Config::elafgiftHigh('2024-01-01'));
check('rates are versioned by date (a later row overrides an earlier one)', (function () {
    Config::override(['elafgiftHighByDate' => ['2024-01-01' => 1.0, '2025-01-01' => 2.0]]);
    $ok = Config::elafgiftHigh('2024-12-31') === 1.0 && Config::elafgiftHigh('2025-01-01') === 2.0;
    Config::reset(); Config::load();
    return $ok;
})());

echo "coverage of the current year\n";
$ref = new ReflectionClass('Config');
$prop = $ref->getProperty('values'); $prop->setAccessible(true);
$values = $prop->getValue();
foreach (['elafgiftHighByDate' => 'HIGH (elafgift)', 'elafgiftLowByDate' => 'LOW (elvarmeafgift)'] as $key => $label) {
    $years = array_map(fn($d) => (int) substr($d, 0, 4), array_keys($values[$key]));
    check("$label table has a row for $year or later", max($years) >= $year,
        "— newest row is " . max($years) . "; check skat.dk and add a row in includes/Config.php");
}

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
