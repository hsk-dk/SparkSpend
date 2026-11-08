<?php
/**
 * Restore the databases from a backup made by cron/backup.php.
 *
 *   php cron/restore_backup.php <backup-dir|latest> --verify
 *       Only check the backup (checksums, decompression, SQLite integrity). Changes nothing.
 *
 *   php cron/restore_backup.php <backup-dir|latest> --to=/some/empty/dir
 *       Restore into another directory (a restore test). The live databases are not touched.
 *
 *   php cron/restore_backup.php <backup-dir|latest> --force
 *       Restore over the live databases. The existing files are moved aside as
 *       <file>.before-restore-<time>, never deleted. Pause the cron jobs first (docs/backup-restore.md).
 *
 * Exit code: 0 ok, 1 failed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../includes/configuration.php';

umask(0077);

$args  = array_slice($argv ?? [], 1);
$flags = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$pos   = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$opt   = static function (string $name) use ($flags): ?string {
    foreach ($flags as $f) {
        if ($f === "--{$name}") return '1';
        if (str_starts_with($f, "--{$name}=")) return substr($f, strlen($name) + 3);
    }
    return null;
};
foreach ($flags as $f) {
    if (!in_array(explode('=', substr($f, 2), 2)[0], ['verify', 'to', 'force'], true)) { fwrite(STDERR, "Unknown option {$f}\n"); exit(1); }
}
if (count($pos) !== 1) { fwrite(STDERR, "Usage: php cron/restore_backup.php <backup-dir|latest> [--verify | --to=DIR | --force]\n"); exit(1); }

$dir = $pos[0];
if ($dir === 'latest') {
    $n = Config::backupDir() !== '' && is_dir(Config::backupDir()) ? Backup::newest(Config::backupDir()) : null;
    if ($n === null) { fwrite(STDERR, "No backup found in BACKUP_DIR.\n"); exit(1); }
    $dir = $n['dir'];
}
if (!is_dir($dir)) { fwrite(STDERR, "Not a directory: {$dir}\n"); exit(1); }

try {
    $problems = Backup::verify($dir);
    if ($problems !== []) {
        fwrite(STDERR, 'Backup ' . basename($dir) . " is NOT usable:\n  - " . implode("\n  - ", $problems) . "\n");
        exit(1);
    }
    echo 'Backup ' . basename($dir) . ": checksums and integrity ok.\n";
    if ($opt('verify') !== null) exit(0);

    $appRoot = realpath(__DIR__ . '/..');
    $abs = static fn(string $p): string => (str_starts_with($p, '/') || preg_match('/^[a-z]:/i', $p)) ? $p : $appRoot . '/' . $p;
    $to = $opt('to');

    if ($to !== null) {
        $to = rtrim($to, '/\\');
        $targets = ['charging_data' => "{$to}/charging_data.db", 'powerlog_data' => "{$to}/powerlog_data.db"];
        $force = false;
    } else {
        if ($opt('force') === null) { fwrite(STDERR, "Restoring over the live databases needs --force (or use --verify / --to=DIR).\n"); exit(1); }
        // Writers must be stopped, or they would write into the old file while it is being replaced.
        foreach (['monta', 'heatpump', 'hus', 'prices'] as $job) {
            if (CronJob::isRunning($job)) { fwrite(STDERR, "The '{$job}' cron job is running. Pause cron and wait for it to finish.\n"); exit(1); }
        }
        $targets = ['charging_data' => $abs(Config::dbPath()), 'powerlog_data' => $abs(Config::powerlogDbPath())];
        $force = true;
    }

    foreach (Backup::restore($dir, $targets, $force) as $line) echo $line . "\n";
    echo $to === null
        ? "Done. Next: php migrate.php, then php doctor.php, then resume cron.\n"
        : "Done. The live databases were not touched.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . "\n");
    exit(1);
}
