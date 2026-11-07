<?php
/**
 * Exposure check: which files can be fetched over HTTP that never should? The app lives in the web root
 * as a git checkout, so .env, .git, the SQLite databases, the logs, the migrations and the source are all
 * files the web server would hand out unless it is told not to - and nothing in the repository tells it.
 *
 * The check is a probe: GET each path on a running server and see the status. Used by `php doctor.php
 * --probe=URL`; the classification is pure and tested (dev/test_security.php).
 *
 * The probe never requests a script that would DO something when fetched (cron/*.php, migrate.php,
 * doctor.php): fetching it is running it - which is how this gap was found, by accident, on production.
 */
final class Exposure
{
    /** Paths that must not be served. path => why */
    public const SECRET = [
        '/.env'                        => 'secrets: API keys, MySQL and Monta credentials',
        '/.env.dev'                    => 'environment file',
        '/.git/config'                 => 'the repository (full source and history can follow)',
        '/.git/HEAD'                   => 'the repository',
        '/data/charging_data.db'       => 'the charges database',
        '/data/powerlog_data.db'       => 'the meter database',
        '/cron/cron.log'               => 'job logs',
        '/config/tax_rates.json'       => 'internal configuration',
        '/migrations/charges/001_initial_schema.php' => 'schema source',
        '/includes/Config.php'         => 'source code (and runs when fetched)',
        '/routes/get_vehicles.php'     => 'route source (and runs when fetched, outside the router)',
        '/dev/test_routes.php'         => 'test code',
        '/README.md'                   => 'documentation of the internals',
        '/BACKLOG.md'                  => 'internal notes',
        '/.gitignore'                  => 'repository metadata',
        '/reset_heatpump_database.sh'  => 'maintenance script',
    ];

    /** Paths that are fine (or required) to serve: only checked to make sure the probe reaches the app. */
    public const PUBLIC = ['/robots.txt', '/manifest.json', '/includes/favicon.svg'];

    /**
     * @return array{level:string,detail:string}  level: ok | warn | fail
     *   fail - the file is served (2xx)
     *   warn - redirected (probably to a login: protected by the proxy, but not proven closed from inside)
     *   ok   - refused or not found (401/403/404/...)
     */
    public static function classify(string $path, int $status): array
    {
        if ($status === 0)                    return ['level' => 'warn', 'detail' => 'no answer'];
        if ($status >= 200 && $status < 300)  return ['level' => 'fail', 'detail' => "served (HTTP {$status}): " . (self::SECRET[$path] ?? '')];
        if ($status >= 300 && $status < 400)  return ['level' => 'warn', 'detail' => "redirects (HTTP {$status}) - not reached the app, probe the server directly"];
        if ($status >= 500)                   return ['level' => 'warn', 'detail' => "server error (HTTP {$status}) - the script ran and failed instead of being refused"];
        return ['level' => 'ok', 'detail' => "refused (HTTP {$status})"];
    }

    /** Is the probe reaching the app at all? (a public file must answer 200, otherwise every "refused" is meaningless) */
    public static function reachesApp(array $publicStatuses): bool
    {
        return in_array(200, $publicStatuses, true);
    }

    /**
     * Fetches the status of a path (follows no redirects; no body is kept).
     * @param string $base  e.g. http://10.0.1.3   @param string|null $host  Host header (virtual hosts)
     */
    public static function status(string $base, string $path, ?string $host = null, int $timeout = 6): int
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
            'header' => $host ? "Host: {$host}\r\nConnection: close\r\n" : "Connection: close\r\n",
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fh = @fopen(rtrim($base, '/') . $path, 'r', false, $ctx);
        if ($fh === false) { $line = $http_response_header[0] ?? ''; }
        else { $meta = stream_get_meta_data($fh); $line = $meta['wrapper_data'][0] ?? ''; fclose($fh); }
        return preg_match('~HTTP/\S+\s+(\d{3})~', (string) $line, $m) ? (int) $m[1] : 0;
    }

    /** @return array<int,array{level:string,name:string,detail:string}> doctor-style results */
    public static function probe(string $base, ?string $host = null, ?callable $fetch = null): array
    {
        $fetch ??= fn(string $p) => self::status($base, $p, $host);
        $out = [];
        $public = array_map($fetch, self::PUBLIC);
        if (!self::reachesApp($public)) {
            return [['level' => 'warn', 'name' => "Sonden når ikke appen på {$base}", 'detail' => 'ingen offentlig fil svarede 200 (login-redirect, forkert adresse eller Host?)']];
        }
        foreach (self::SECRET as $path => $why) {
            $c = self::classify($path, $fetch($path));
            $out[] = ['level' => $c['level'], 'name' => "Ikke tilgængelig udefra: {$path}", 'detail' => $c['detail']];
        }
        return $out;
    }
}
