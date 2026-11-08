<?php
if (!defined('SPARKSPEND_BOOTED')) { http_response_code(404); exit; }
/**
 * Trigger Sync API
 *
 * Launches one or all cron sync scripts in the background (non-blocking).
 *
 * POST body (JSON): { "source": "monta" | "heatpump" | "hus" | "all" }
 */
return static function (Request $request): Response {
    $source = Http::str($request->body(), 'source');

    // Whitelist of allowed sources → absolute script paths
    $scripts = [
        'monta'    => __DIR__ . '/../cron/update_monta_data.php',
        'heatpump' => __DIR__ . '/../cron/sync_heatpump_data.php',
        'hus'      => __DIR__ . '/../cron/sync_housepowerlog_data.php',
    ];

    if ($source === 'all') {
        $toRun = array_values($scripts);
    } elseif (isset($scripts[$source])) {
        $toRun = [$scripts[$source]];
    } else {
        Http::fail('Ukendt kilde');
    }

    // Rate-limit: max 1 sync request per 30 seconds
    $cacheDir     = Config::cacheDir();
    $cooldownFile = $cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_sync_cooldown.lock';
    if (file_exists($cooldownFile) && (time() - filemtime($cooldownFile)) < 30) {
        Http::fail('Vent venligst 30 sekunder mellem synkroniseringer', 429);
    }
    touch($cooldownFile);

    // Resolve PHP CLI binary
    $php     = BackgroundJob::phpCli();
    $logFile = __DIR__ . '/../cron/trigger.log';
    $logPath = escapeshellarg($logFile);

    // Invalidate all file caches
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_*.json') ?: [] as $f) {
        @unlink($f);
    }

    // The jobs lock themselves (CronJob): a script that is started while its previous run is still going
    // logs "Already running" and exits, so no wrapper lock is needed here. We only look, to leave the log
    // alone while a run is still writing to it.
    $jobNames   = array_flip($scripts);          // script path => job name ('monta' | 'heatpump' | 'hus')
    $anyRunning = false;
    foreach ($toRun as $script) {
        if (CronJob::isRunning($jobNames[$script])) $anyRunning = true;
    }
    // Truncate the log before a triggered sync - but not while one is still writing to it
    if (!$anyRunning) {
        file_put_contents($logFile, '');
    }

    $started = 0;
    foreach ($toRun as $script) {
        if (!file_exists($script) || CronJob::isRunning($jobNames[$script])) {
            continue;
        }

        $cmd = $php . ' ' . escapeshellarg($script) . ' >> ' . $logPath . ' 2>&1';

        if (BackgroundJob::run($cmd)) {
            $started++;
        }
    }

    return Response::json(['success' => true, 'started' => $started]);
};
