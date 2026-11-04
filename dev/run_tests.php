<?php
/**
 * Runs every test in dev/: dev/test_*.php (with PHP) and dev/test_*.js (with Node,
 * skipped with a notice if Node is not installed).
 *
 *   php dev/run_tests.php            # all tests, summary at the end
 *   php dev/run_tests.php -v         # also print each test's full output
 *   php dev/run_tests.php pricing    # only tests whose file name contains "pricing"
 *
 * Exit code 0 = all passed, 1 = at least one failed.
 */
$verbose = in_array('-v', $argv, true);
$filters = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '-v'));

function commandExists(string $cmd): bool {
    $probe = stripos(PHP_OS_FAMILY, 'Windows') !== false ? "where $cmd 2>NUL" : "command -v $cmd 2>/dev/null";
    exec($probe, $out, $code);
    return $code === 0;
}

$tests = array_merge(glob(__DIR__ . '/test_*.php') ?: [], glob(__DIR__ . '/test_*.js') ?: []);
sort($tests);

$nodeOk  = commandExists('node');
$results = [];
foreach ($tests as $file) {
    $name = basename($file);
    if ($filters && !array_filter($filters, fn($f) => str_contains($name, $f))) continue;

    if (str_ends_with($file, '.js')) {
        if (!$nodeOk) { $results[] = [$name, 'skip', 'node not installed']; continue; }
        $cmd = 'node ' . escapeshellarg($file);
    } else {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    }

    $start = microtime(true);
    exec($cmd . ' 2>&1', $out, $code);
    $secs = microtime(true) - $start;
    $text = implode("\n", $out);
    $out  = [];

    $summary = '';
    if (preg_match_all('/(\d+) checks?, (\d+) failed/', $text, $m, PREG_SET_ORDER)) {
        $last = end($m);
        $summary = "{$last[1]} checks";
    }
    $results[] = [$name, $code === 0 ? 'pass' : 'FAIL', sprintf('%s %.1fs', $summary, $secs), $text];
    if ($verbose || $code !== 0) echo "── $name ──\n$text\n\n";
}

$failed = 0;
echo str_repeat('=', 60) . "\n";
foreach ($results as $r) {
    if ($r[1] === 'FAIL') $failed++;
    printf("  %-5s %-34s %s\n", $r[1], $r[0], $r[2]);
}
$ran = count(array_filter($results, fn($r) => $r[1] !== 'skip'));
echo "\n$ran test file(s) run, $failed failed\n";
exit($failed === 0 ? 0 : 1);
