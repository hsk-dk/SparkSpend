<?php
/**
 * Warms the electricity-price cache (Energi Data Service).
 *
 * Energi Data Service rate-limits hard: a few requests, then HTTP 429 "try again in N
 * seconds". A web request that needs two years of prices on a cold cache cannot wait
 * that out, so it shows what it has. This CLI job has no time limit: it fetches the
 * needed years one at a time, sleeps through each 429 pause, and leaves the cache full,
 * so the web views never have to call upstream themselves.
 *
 * Safe to run often (it only fetches what is missing or expired). Suggested: every
 * 30 minutes, and once right after deploy.
 *
 * Usage: php cron/warm_prices.php [years-back]     (default: 3 calendar years)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../includes/configuration.php';
require_once __DIR__ . '/../includes/ElspotPrices.php';

$area = Config::elspotArea();
$gln  = Config::elspotGln();
if ($area === '' || $gln === '') {
    echo "ELSPOT_AREA / ELSPOT_GLN not configured - nothing to warm.\n";
    exit(0);
}

$yearsBack = max(0, (int) ($argv[1] ?? 3) - 1);
$start = (date('Y') - $yearsBack) . '-01-01';
$end   = date('Y-m-t');

$maxWait     = 900;   // give up if upstream asks us to wait longer than this in total
$waited      = 0;
$attempt     = 0;

echo date('c') . " warming prices $start .. $end ($area, GLN $gln)\n";
do {
    $attempt++;
    // 'high' and 'low' share the same raw upstream data, so the second call is free.
    $high = ElspotPrices::compute($start, $end, $area, $gln, 'high');
    $low  = ElspotPrices::compute($start, $end, $area, $gln, 'low');
    if ($high['complete'] && $low['complete']) {
        echo date('c') . " done after $attempt attempt(s): {$high['components']['spot_hours']} spot hours, "
           . "nettarif records {$high['components']['nettarif_records']}\n";
        exit(0);
    }
    $pause = eds_backoff_remaining();
    if ($pause <= 0) $pause = 5;      // incomplete for another reason: short retry
    if ($waited + $pause > $maxWait) break;
    echo date('c') . " incomplete (attempt $attempt), upstream rate limit - waiting {$pause}s\n";
    sleep($pause + 1);
    $waited += $pause + 1;
} while (true);

echo date('c') . " stopped: still incomplete after waiting {$waited}s - run again later.\n";
exit(1);
