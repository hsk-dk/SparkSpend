<?php
/**
 * Starts a shell command in the background from a web request (used by trigger_sync to launch the cron scripts)
 * and finds the PHP command-line binary to run them with. Was two loose functions at the bottom of
 * routes/trigger_sync.php.
 */
final class BackgroundJob
{
    /** Resolve the PHP CLI binary path (the web server's PHP is usually php-fpm, which cannot run scripts). */
    public static function phpCli(): string
    {
        $fpmBinary = PHP_BINARY;

        $version = '';
        if (preg_match('/(\d+\.\d+)$/', $fpmBinary, $m)) {
            $version = $m[1];
        }

        $candidates = [];
        if ($version !== '') {
            $candidates[] = '/usr/bin/php' . $version;
        }
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return escapeshellcmd($path);
            }
        }

        return escapeshellcmd($fpmBinary);
    }

    /** Run a shell command in the background. @return bool false if no way of starting it was available */
    public static function run(string $cmd): bool
    {
        if (function_exists('exec')) {
            exec($cmd . ' &');
            return true;
        }

        if (function_exists('proc_open')) {
            $spec  = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
            $pipes = [];
            $proc  = proc_open($cmd . ' &', $spec, $pipes);
            if ($proc !== false) {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($proc);
                return true;
            }
        }

        if (function_exists('shell_exec')) {
            shell_exec($cmd . ' &');
            return true;
        }

        if (function_exists('popen')) {
            $handle = popen($cmd . ' &', 'r');
            if ($handle !== false) {
                pclose($handle);
                return true;
            }
        }

        return false;
    }
}
