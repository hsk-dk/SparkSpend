<?php
/**
 * Backup and restore of the two SQLite databases (charging_data.db, powerlog_data.db).
 *
 * Why not just copy the files: both databases run in WAL mode and are written by cron every few
 * minutes. A plain `cp` of an open database can capture a half-written state (and misses the -wal
 * file). `VACUUM INTO` writes a consistent snapshot through SQLite itself, without stopping the app.
 *
 * A backup is a directory  <BACKUP_DIR>/YYYYMMDD-HHMMSS/  holding one  <name>.db.gz  per database and
 * a manifest.json (sizes, SHA-256 of the stored files, integrity result, migrations applied). It is
 * built under a ".partial" name and renamed at the very end, so a directory without a manifest is
 * never mistaken for a finished backup.
 *
 * Secrets (.env) are NOT part of a backup. They are backed up separately and encrypted, see
 * docs/backup-restore.md.
 *
 * The CLI wrappers are cron/backup.php and cron/restore_backup.php; the logic lives here so it can be
 * tested (php dev/test_backup.php).
 */
final class Backup
{
    public const FORMAT = 1;
    private const DIR_PATTERN = '/^\d{8}-\d{6}$/';

    // ── Creating ─────────────────────────────────────────────────────────────

    /**
     * Snapshot every source into a new backup directory.
     *
     * @param array<string,string> $sources  logical name => database path (e.g. 'charging_data' => '/…/charging_data.db')
     * @return string the finished backup directory
     * @throws RuntimeException on any problem; nothing half-finished is left behind
     */
    public static function create(array $sources, string $destRoot, ?int $now = null): string
    {
        $now ??= time();
        if ($sources === []) throw new RuntimeException('No databases to back up.');
        self::ensureDir($destRoot);

        $need = 0;
        foreach ($sources as $name => $path) {
            self::assertName($name);
            if (!is_file($path)) throw new RuntimeException("Database not found: {$path}");
            $need += (int) filesize($path);
        }
        $free = @disk_free_space($destRoot);
        // Raw snapshot + compressed copy exist side by side for a moment.
        if ($free !== false && $free < $need * 2 + 1048576) {
            throw new RuntimeException('Not enough free space in ' . $destRoot);
        }

        $stamp = gmdate('Ymd-His', $now);
        $final = $destRoot . DIRECTORY_SEPARATOR . $stamp;
        $tmp   = $final . '.partial';
        if (file_exists($final) || file_exists($tmp)) {
            throw new RuntimeException("A backup named {$stamp} already exists (wait a second and retry).");
        }
        if (!@mkdir($tmp, 0700)) throw new RuntimeException("Cannot create {$tmp}");

        try {
            $files = [];
            foreach ($sources as $name => $path) {
                $files[$name] = self::snapshotOne($name, $path, $tmp);
            }
            $manifest = [
                'format'      => self::FORMAT,
                'created_utc' => gmdate('c', $now),
                'host'        => php_uname('n'),
                'php'         => PHP_VERSION,
                'sqlite'      => self::sqliteVersion(),
                'databases'   => $files,
            ];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false || file_put_contents($tmp . DIRECTORY_SEPARATOR . 'manifest.json', $json . "\n") === false) {
                throw new RuntimeException('Cannot write manifest.json');
            }
            @chmod($tmp . DIRECTORY_SEPARATOR . 'manifest.json', 0600);
            if (!@rename($tmp, $final)) throw new RuntimeException("Cannot finish backup {$final}");
        } catch (\Throwable $e) {
            self::removeDir($tmp);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
        return $final;
    }

    /** @return array{file:string,bytes:int,sha256:string,raw_bytes:int,integrity:string,migrations:int} */
    private static function snapshotOne(string $name, string $path, string $dir): array
    {
        $raw = $dir . DIRECTORY_SEPARATOR . $name . '.db';
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout=30000');
        // VACUUM INTO takes a read lock for the duration; in WAL mode writers keep going.
        $db->exec('VACUUM INTO ' . $db->quote($raw));
        $db = null;
        @chmod($raw, 0600);

        // Check the snapshot itself, not the source: this is what a restore would use.
        $check = self::inspect($raw);
        if ($check['integrity'] !== 'ok') {
            @unlink($raw);
            throw new RuntimeException("Integrity check failed for the snapshot of {$name}: " . $check['integrity']);
        }
        $rawBytes = (int) filesize($raw);

        $gz = $raw . '.gz';
        self::gzipFile($raw, $gz);
        @unlink($raw);
        @chmod($gz, 0600);

        return [
            'file'       => basename($gz),
            'bytes'      => (int) filesize($gz),
            'sha256'     => (string) hash_file('sha256', $gz),
            'raw_bytes'  => $rawBytes,
            'integrity'  => $check['integrity'],
            'migrations' => $check['migrations'],
        ];
    }

    // ── Verifying ────────────────────────────────────────────────────────────

    /**
     * Prove a backup is usable without touching the live databases: checksums match, each file
     * decompresses, and the decompressed database passes SQLite's integrity check.
     *
     * @return string[] problems; empty = the backup is good
     */
    public static function verify(string $dir): array
    {
        $problems = [];
        $manifest = self::readManifest($dir, $problems);
        if ($manifest === null) return $problems;

        $scratch = self::scratchDir();
        try {
            foreach ($manifest['databases'] as $name => $info) {
                $gz = $dir . DIRECTORY_SEPARATOR . ($info['file'] ?? '');
                if (!is_file($gz)) { $problems[] = "{$name}: file missing ({$info['file']})"; continue; }
                if (!hash_equals((string) ($info['sha256'] ?? ''), (string) hash_file('sha256', $gz))) {
                    $problems[] = "{$name}: checksum mismatch (the file changed or is damaged)";
                    continue;
                }
                $out = $scratch . DIRECTORY_SEPARATOR . $name . '.db';
                try {
                    self::gunzipFile($gz, $out);
                    $check = self::inspect($out);
                    if ($check['integrity'] !== 'ok') $problems[] = "{$name}: integrity check: " . $check['integrity'];
                } catch (\Throwable $e) {
                    $problems[] = "{$name}: " . $e->getMessage();
                }
            }
        } finally {
            self::removeDir($scratch);
        }
        return $problems;
    }

    // ── Restoring ────────────────────────────────────────────────────────────

    /**
     * Put the databases of a backup in place.
     *
     * The backup is verified first. Each existing target is moved aside (never deleted) as
     * <file>.before-restore-<time>, so a wrong restore can itself be undone. Stale -wal/-shm files of
     * the replaced database are removed: left behind they would be replayed onto the restored file.
     *
     * @param array<string,string> $targets  logical name => where the database must end up
     * @return string[] what was done, one line per step
     */
    public static function restore(string $dir, array $targets, bool $replaceExisting, ?int $now = null): array
    {
        $now ??= time();
        $problems = self::verify($dir);
        if ($problems !== []) throw new RuntimeException("Backup is not usable:\n  - " . implode("\n  - ", $problems));

        $ignored = [];
        $manifest = self::readManifest($dir, $ignored);
        foreach ($manifest['databases'] as $name => $_) {
            if (!isset($targets[$name])) throw new RuntimeException("No target path for '{$name}'.");
            if (is_file($targets[$name]) && !$replaceExisting) {
                throw new RuntimeException("{$targets[$name]} exists. Use --force to move it aside and restore.");
            }
        }

        $log = [];
        // Decompress everything next to its target first, so a failure here changes nothing live.
        $staged = [];
        try {
            foreach ($manifest['databases'] as $name => $info) {
                self::ensureDir(dirname($targets[$name]));
                $stage = $targets[$name] . '.restore-tmp';
                $staged[$name] = $stage;                       // registered first so a failure below cleans it up
                self::gunzipFile($dir . DIRECTORY_SEPARATOR . $info['file'], $stage);
                if (self::inspect($stage)['integrity'] !== 'ok') throw new RuntimeException("{$name}: integrity check failed after decompression.");
            }
        } catch (\Throwable $e) {
            foreach ($staged as $s) @unlink($s);
            throw $e;
        }

        $suffix = '.before-restore-' . gmdate('Ymd-His', $now);
        foreach ($staged as $name => $stage) {
            $target = $targets[$name];
            if (is_file($target)) {
                if (!@rename($target, $target . $suffix)) throw new RuntimeException("Cannot move {$target} aside.");
                $log[] = "{$name}: moved existing database to " . basename($target) . $suffix;
            }
            foreach (['-wal', '-shm'] as $ext) {
                if (is_file($target . $ext)) {
                    @rename($target . $ext, $target . $ext . $suffix);
                    $log[] = "{$name}: moved stale {$ext} file aside";
                }
            }
            if (!@rename($stage, $target)) throw new RuntimeException("Cannot put the restored database at {$target}.");
            @chmod($target, 0640);
            $log[] = "{$name}: restored to {$target}";
        }
        return $log;
    }

    // ── Listing and pruning ──────────────────────────────────────────────────

    /** @return string[] finished backup directories (with a manifest), oldest first */
    public static function list(string $destRoot): array
    {
        $out = [];
        foreach (glob($destRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) {
            if (preg_match(self::DIR_PATTERN, basename($d)) && is_file($d . DIRECTORY_SEPARATOR . 'manifest.json')) $out[] = $d;
        }
        sort($out);
        return $out;
    }

    /**
     * Keep the newest $keepDaily backups plus the newest one of each of the last $keepMonthly months;
     * delete the rest. Only directories named like a backup are ever touched.
     *
     * @return string[] deleted directories
     */
    public static function prune(string $destRoot, int $keepDaily, int $keepMonthly): array
    {
        $all = array_reverse(self::list($destRoot));          // newest first
        $keep = array_slice($all, 0, max(1, $keepDaily));      // never delete the newest backup
        $months = [];
        foreach ($all as $d) {
            $m = substr(basename($d), 0, 6);
            if (!isset($months[$m]) && count($months) < $keepMonthly) { $months[$m] = $d; }
        }
        $keep = array_unique(array_merge($keep, array_values($months)));
        $deleted = [];
        foreach ($all as $d) {
            if (!in_array($d, $keep, true)) { self::removeDir($d); $deleted[] = $d; }
        }
        // Leftovers of backups that died half way.
        foreach (glob($destRoot . DIRECTORY_SEPARATOR . '*.partial', GLOB_ONLYDIR) ?: [] as $p) {
            if (filemtime($p) < time() - 86400) { self::removeDir($p); $deleted[] = $p; }
        }
        return $deleted;
    }

    /** @return array{dir:string,created:int}|null the newest finished backup */
    public static function newest(string $destRoot): ?array
    {
        $all = self::list($destRoot);
        if ($all === []) return null;
        $dir = end($all);
        $p = [];
        $m = self::readManifest($dir, $p);
        $ts = $m ? strtotime((string) $m['created_utc']) : false;
        return ['dir' => $dir, 'created' => $ts !== false ? $ts : (int) filemtime($dir)];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{integrity:string,migrations:int} */
    public static function inspect(string $dbFile): array
    {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        $integrity = count($rows) === 1 ? (string) $rows[0] : implode('; ', array_slice($rows, 0, 3));
        $migrations = 0;
        $has = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='_migrations'")->fetchColumn();
        if ($has) $migrations = (int) $db->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
        return ['integrity' => $integrity, 'migrations' => $migrations];
    }

    /** @param string[] $problems filled with what is wrong when null is returned */
    private static function readManifest(string $dir, array &$problems): ?array
    {
        $problems = [];
        $file = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($file)) { $problems[] = 'manifest.json is missing (unfinished backup?)'; return null; }
        $m = json_decode((string) file_get_contents($file), true);
        if (!is_array($m) || ($m['format'] ?? null) !== self::FORMAT || !is_array($m['databases'] ?? null) || $m['databases'] === []) {
            $problems[] = 'manifest.json is unreadable or from an unknown format';
            return null;
        }
        foreach ($m['databases'] as $name => $info) {
            try { self::assertName((string) $name); } catch (RuntimeException $e) { $problems[] = $e->getMessage(); return null; }
            // The manifest is untrusted input: a file name must stay inside the backup directory.
            if (!is_array($info) || !isset($info['file']) || basename((string) $info['file']) !== $info['file']) {
                $problems[] = "{$name}: invalid file entry in manifest.json";
                return null;
            }
        }
        return $m;
    }

    private static function assertName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException("Invalid database name '{$name}'.");
    }

    private static function sqliteVersion(): string
    {
        try { return (string) (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn(); }
        catch (\Throwable $e) { return 'unknown'; }
    }

    private static function gzipFile(string $src, string $dst): void
    {
        $in = fopen($src, 'rb'); $out = gzopen($dst, 'wb6');
        if ($in === false || $out === false) throw new RuntimeException("Cannot compress {$src}");
        while (!feof($in)) { $buf = fread($in, 1048576); if ($buf === false || gzwrite($out, $buf) === false) throw new RuntimeException("Write failed: {$dst}"); }
        fclose($in); gzclose($out);
    }

    private static function gunzipFile(string $src, string $dst): void
    {
        $in = gzopen($src, 'rb'); $out = fopen($dst, 'wb');
        if ($in === false || $out === false) throw new RuntimeException("Cannot decompress {$src}");
        while (!gzeof($in)) { $buf = gzread($in, 1048576); if ($buf === false || fwrite($out, $buf) === false) throw new RuntimeException("Write failed: {$dst}"); }
        gzclose($in); fclose($out);
        @chmod($dst, 0600);
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException("Cannot create directory {$dir}");
        if (!is_writable($dir)) throw new RuntimeException("Directory is not writable: {$dir}");
    }

    private static function scratchDir(): string
    {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkspend_verify_' . bin2hex(random_bytes(6));
        if (!@mkdir($d, 0700)) throw new RuntimeException("Cannot create {$d}");
        return $d;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($p) ? self::removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
