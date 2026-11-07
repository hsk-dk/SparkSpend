<?php
/**
 * Preflight checks for a deployment ("doctor"): the mistakes that actually happened in production -
 * a placeholder path in the crontab, a missing CACHE_DIR, migrations nobody ran, a job that was never
 * scheduled. The pure checks live here and are unit-tested (php dev/test_doctor.php); the CLI that
 * gathers the facts and prints the result is doctor.php.
 *
 * Every check returns ['level' => 'ok'|'warn'|'fail', 'name' => ..., 'detail' => ...].
 */
final class Doctor
{
    /** The scheduled jobs and how often they should run (for the message only). */
    public const JOBS = [
        'update_monta_data.php'         => 'hver 15. minut',
        'sync_heatpump_data.php'        => 'hvert 5. minut',
        'sync_housepowerlog_data.php'   => 'hvert 5. minut',
        'warm_prices.php'               => 'hver 30. minut',
    ];

    private static function r(string $level, string $name, string $detail = ''): array
    {
        return ['level' => $level, 'name' => $name, 'detail' => $detail];
    }

    /**
     * @param array<string,string> $env  the .env values by name
     * @return array<int,array>
     */
    public static function checkEnv(array $env): array
    {
        $out = [];
        $need = [
            'ELSPOT_AREA' => 'prisområde (DK1/DK2)', 'ELSPOT_GLN' => 'netselskabets GLN (tariffer)',
            'VEHICLE_API_KEY' => 'nøgle til Home Assistant-endpoints',
        ];
        foreach ($need as $k => $why) {
            $out[] = trim((string) ($env[$k] ?? '')) !== ''
                ? self::r('ok', "$k er sat")
                : self::r('fail', "$k mangler", $why);
        }
        $key = (string) ($env['VEHICLE_API_KEY'] ?? '');
        if ($key !== '' && strlen($key) < 24) $out[] = self::r('warn', 'VEHICLE_API_KEY er kort', 'brug mindst 24 tilfældige tegn');
        if (trim((string) ($env['HEALTH_KEY'] ?? '')) === '') {
            $out[] = self::r('warn', 'HEALTH_KEY mangler', 'uden den svarer /health.php 503 og Uptime Kuma kan ikke overvåge');
        } elseif (($env['HEALTH_KEY'] ?? '') === $key) {
            $out[] = self::r('warn', 'HEALTH_KEY er den samme som VEHICLE_API_KEY', 'brug en egen nøgle (skrivebeskyttet)');
        }
        foreach (['MONTA_CLIENT_ID', 'MONTA_CLIENT_SECRET', 'MYSQL_HEATPUMP_HOST', 'MYSQL_HEATPUMP_USER', 'MYSQL_HEATPUMP_DATABASE'] as $k) {
            if (trim((string) ($env[$k] ?? '')) === '') $out[] = self::r('warn', "$k mangler", 'det tilhørende sync-job kan ikke køre');
        }
        if (strtolower((string) ($env['DEBUG'] ?? 'false')) === 'true') $out[] = self::r('warn', 'DEBUG=true', 'interne fejltekster vises for klienten');
        if (strtolower((string) ($env['EDS_WEB_FETCH'] ?? 'true')) !== 'false') {
            $out[] = self::r('warn', 'EDS_WEB_FETCH er ikke false', 'websider kan kalde Energi Data Service og ramme dens rate limit; lad kun warm_prices.php gøre det');
        }
        if (strtolower((string) ($env['AUTO_MIGRATE'] ?? 'true')) !== 'false') {
            $out[] = self::r('warn', 'AUTO_MIGRATE er ikke false', 'migrering sker ved en webforespørgsel; kør i stedet php migrate.php ved udrulning');
        }
        return $out;
    }

    /**
     * @param string   $crontab  output of `crontab -l` and/or the lines of /etc/crontab
     * @param callable $exists   fn(string $path): bool  (injected so the test needs no files)
     */
    public static function checkCrontab(string $crontab, callable $exists): array
    {
        $out = [];
        $lines = array_filter(array_map('trim', preg_split('/\R/', $crontab) ?: []), fn($l) => $l !== '' && $l[0] !== '#');
        foreach (self::JOBS as $script => $how) {
            $line = null;
            foreach ($lines as $l) if (str_contains($l, $script)) { $line = $l; break; }
            if ($line === null) { $out[] = self::r('fail', "$script er ikke planlagt", "skal køre $how"); continue; }
            if (!preg_match('~(/\S*' . preg_quote($script, '~') . ')~', $line, $m)) { $out[] = self::r('warn', "$script: kan ikke læse stien", $line); continue; }
            $path = $m[1];
            if (str_contains($path, '/sti/til') || str_contains($path, 'path/to')) {
                $out[] = self::r('fail', "$script: stien er en pladsholder", $path);
            } elseif (!$exists($path)) {
                $out[] = self::r('fail', "$script: filen findes ikke", $path);
            } else {
                $out[] = self::r('ok', "$script er planlagt", $path);
            }
        }
        return $out;
    }

    /** Is a directory usable (exists + writable)? $label names it in the message. */
    public static function checkDir(string $label, string $path, bool $exists, bool $writable, ?string $tmpDir = null): array
    {
        if (!$exists)   return self::r('fail', "$label findes ikke", $path);
        if (!$writable) return self::r('fail', "$label er ikke skrivbar", $path);
        if ($tmpDir !== null && rtrim($path, '/\\') === rtrim($tmpDir, '/\\')) {
            return self::r('warn', "$label er systemets temp-mappe", "$path - sæt CACHE_DIR til en fast mappe, ellers kan prisfilerne forsvinde");
        }
        return self::r('ok', "$label er i orden", $path);
    }

    /** @param array<int,array{filename:string,status:string}> $status from MigrationRunner::status */
    public static function checkMigrations(string $label, array $status): array
    {
        $pending = array_values(array_filter($status, fn($m) => ($m['status'] ?? '') !== 'applied'));
        return $pending === []
            ? self::r('ok', "$label: alle migreringer er kørt")
            : self::r('fail', "$label: " . count($pending) . ' migrering(er) mangler', implode(', ', array_column($pending, 'filename')) . ' - kør php migrate.php');
    }

    /** Turns the Freshness verdict into checks (one per source that is not ok). */
    public static function checkFreshness(array $verdict): array
    {
        $bad = array_filter($verdict['checks'] ?? [], fn($c) => $c['status'] !== 'ok');
        if (!$bad) return [self::r('ok', 'Alle datakilder er friske')];
        $out = [];
        foreach ($bad as $c) {
            $out[] = self::r(!empty($c['optional']) ? 'warn' : 'fail', $c['label'] . ': ' . $c['status'],
                $c['error'] ?? ($c['age_sec'] === null ? 'aldrig modtaget' : round($c['age_sec'] / 60) . ' min gammel (grænse ' . round($c['max_age_sec'] / 60) . ')'));
        }
        return $out;
    }

    /** Worst level wins. */
    public static function worst(array $results): string
    {
        $levels = array_column($results, 'level');
        return in_array('fail', $levels, true) ? 'fail' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }
}
