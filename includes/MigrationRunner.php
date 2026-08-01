<?php
/**
 * Database Migration Runner for SparkSpend
 *
 * Applies versioned schema migrations to SQLite databases.
 * Tracks applied migrations in a `_migrations` table within each database.
 *
 * Migration files are numbered PHP scripts that return an array:
 *   return ['description' => '...', 'up' => ['SQL statement', ...]];
 *
 * Usage:
 *   MigrationRunner::run($db, '/path/to/migrations/charges');
 *   MigrationRunner::status($db, '/path/to/migrations/charges');
 */

require_once __DIR__ . '/Config.php';

class MigrationRunner {

    /**
     * Run all pending migrations for a database.
     *
     * @param PDO    $db            Database connection
     * @param string $migrationsDir Absolute path to the migrations directory
     * @return array Array of applied migration filenames (empty if none pending)
     */
    public static function run(PDO $db, string $migrationsDir): array {
        self::ensureMigrationsTable($db);

        $applied = self::getApplied($db);
        $files   = self::getMigrationFiles($migrationsDir);
        $ran     = [];

        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $applied, true)) {
                continue;
            }

            $migration = require $file;
            if (!is_array($migration) || empty($migration['up'])) {
                continue;
            }

            $db->beginTransaction();
            try {
                foreach ($migration['up'] as $sql) {
                    $db->exec($sql);
                }

                $stmt = $db->prepare("INSERT INTO _migrations (filename, description, applied_at) VALUES (?, ?, ?)");
                $stmt->execute([
                    $filename,
                    $migration['description'] ?? '',
                    date('Y-m-d H:i:s'),
                ]);

                $db->commit();
                $ran[] = $filename;
            } catch (\Throwable $e) {
                $db->rollBack();
                error_log("MigrationRunner: Failed to apply {$filename}: " . $e->getMessage());
                throw new \RuntimeException("Migration {$filename} failed: " . $e->getMessage(), 0, $e);
            }
        }

        return $ran;
    }

    /**
     * Run migrations only if they haven't been checked recently.
     * Uses a file-based flag to avoid checking on every request.
     *
     * @param PDO    $db            Database connection
     * @param string $migrationsDir Absolute path to the migrations directory
     * @param int    $ttl           Seconds between checks (default: 3600 = 1 hour)
     * @return array Applied migrations (empty if skipped or none pending)
     */
    public static function runIfDue(PDO $db, string $migrationsDir, int $ttl = 3600): array {
        $cacheDir  = Config::cacheDir();
        $flagFile  = $cacheDir . DIRECTORY_SEPARATOR . 'sparkspend_migrations_' . md5($migrationsDir) . '.flag';

        if (file_exists($flagFile) && (time() - filemtime($flagFile)) < $ttl) {
            return []; // Already checked recently
        }

        $result = self::run($db, $migrationsDir);

        // Touch the flag file to mark this check as done
        @touch($flagFile);

        return $result;
    }

    /**
     * Get list of applied migration filenames.
     *
     * @param PDO $db Database connection
     * @return array Array of filename strings
     */
    public static function getApplied(PDO $db): array {
        self::ensureMigrationsTable($db);
        $stmt = $db->query("SELECT filename FROM _migrations ORDER BY filename");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get status of all migrations (applied and pending).
     *
     * @param PDO    $db            Database connection
     * @param string $migrationsDir Absolute path to the migrations directory
     * @return array Array of ['filename', 'description', 'status', 'applied_at']
     */
    public static function status(PDO $db, string $migrationsDir): array {
        self::ensureMigrationsTable($db);

        // Get applied migrations with timestamps
        $stmt = $db->query("SELECT filename, description, applied_at FROM _migrations ORDER BY filename");
        $appliedMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $appliedMap[$row['filename']] = $row;
        }

        $files  = self::getMigrationFiles($migrationsDir);
        $result = [];

        foreach ($files as $file) {
            $filename   = basename($file);
            $migration  = require $file;
            $desc       = $migration['description'] ?? '';

            if (isset($appliedMap[$filename])) {
                $result[] = [
                    'filename'    => $filename,
                    'description' => $desc,
                    'status'      => 'applied',
                    'applied_at'  => $appliedMap[$filename]['applied_at'],
                ];
            } else {
                $result[] = [
                    'filename'    => $filename,
                    'description' => $desc,
                    'status'      => 'pending',
                    'applied_at'  => null,
                ];
            }
        }

        return $result;
    }

    /**
     * Create the _migrations tracking table if it doesn't exist.
     */
    private static function ensureMigrationsTable(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS _migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL UNIQUE,
                description TEXT DEFAULT '',
                applied_at TEXT NOT NULL
            )
        ");
    }

    /**
     * Get sorted list of migration files from a directory.
     *
     * @param string $dir Directory path
     * @return array Sorted array of absolute file paths
     */
    private static function getMigrationFiles(string $dir): array {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return [];
        }

        sort($files); // Alphabetical = chronological with NNN_ prefix
        return $files;
    }
}
