<?php
/**
 * Backup of the two SQLite databases (charging_data.db, powerlog_data.db) into BACKUP_DIR.
 * Cron: once a night. The logic is in includes/Backup.php (tested); docs/backup-restore.md has the
 * full procedure, including the separate, encrypted backup of .env.
 *
 * Exit code: 0 ok, 1 failed.
 *
 * Usage: php cron/backup.php            make a backup, verify it, prune old ones
 *        php cron/backup.php --status   show the newest backup; exit 1 if none or older than 36 hours
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../includes/configuration.php';

umask(0077);   // backups hold personal charging data: owner only

$appRoot = realpath(__DIR__ . '/..');
$abs = static fn(string $p): string => (str_starts_with($p, '/') || preg_match('/^[a-z]:/i', $p)) ? $p : $appRoot . '/' . $p;

$dest = Config::backupDir();
if ($dest === '' || !(str_starts_with($dest, '/') || preg_match('/^[a-z]:[\\\\\/]/i', $dest))) {
    fwrite(STDERR, "BACKUP_DIR is not set to an absolute path in .env - refusing to run.\n");
    exit(1);
}
// A backup folder inside the web root would be served to the world (the nginx rules only cover known paths).
$real = realpath($dest) ?: $dest;
$root = str_replace('\\', '/', (string) $appRoot);
if (str_starts_with(str_replace('\\', '/', $real) . '/', $root . '/')) {
    fwrite(STDERR, "BACKUP_DIR must be outside the application directory ({$appRoot}).\n");
    exit(1);
}

if (in_array('--status', $argv ?? [], true)) {
    $n = is_dir($dest) ? Backup::newest($dest) : null;
    if ($n === null) { echo "No backup found in {$dest}\n"; exit(1); }
    $age = time() - $n['created'];
    printf("Newest backup: %s (%.1f hours old)\n", basename($n['dir']), $age / 3600);
    exit($age > 36 * 3600 ? 1 : 0);
}

exit(CronJob::run('backup', 'Backup', function (callable $log) use ($dest, $abs): int {
    $dir = Backup::create([
        'charging_data' => $abs(Config::dbPath()),
        'powerlog_data' => $abs(Config::powerlogDbPath()),
    ], $dest);

    // A backup nobody has opened is a hope, not a backup: prove it before reporting success.
    $problems = Backup::verify($dir);
    if ($problems !== []) throw new RuntimeException('Backup written but failed verification: ' . implode('; ', $problems));

    $deleted = Backup::prune($dest, Config::backupKeepDaily(), Config::backupKeepMonthly());
    $log('Backup ' . basename($dir) . ' verified' . ($deleted ? ', pruned ' . count($deleted) . ' old' : ''));
    return 0;
}));
