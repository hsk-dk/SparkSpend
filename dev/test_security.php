<?php
/**
 * Security guards: PeerGuard (who may reach the pages), Exposure (which files are served), and the
 * "run only inside the bootstrap" guards on the route files and the CLI scripts.
 * Run:  php dev/test_security.php
 */
putenv('SPARKSPEND_ENV_FILE=' . __DIR__ . '/does-not-exist.env');
require __DIR__ . '/../includes/env.php';
require __DIR__ . '/../includes/Config.php';
require __DIR__ . '/../includes/autoload.php';
Config::load();

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}
$root = dirname(__DIR__);

echo "PeerGuard::allowed()\n";
$A = fn(string $ip, array $list) => PeerGuard::allowed($ip, $list);
check('an exact IPv4 address', $A('10.0.1.5', ['10.0.1.5']) && !$A('10.0.1.6', ['10.0.1.5']));
check('a /24 network', $A('10.0.1.200', ['10.0.1.0/24']) && !$A('10.0.2.1', ['10.0.1.0/24']));
check('a /16 network', $A('172.18.44.9', ['172.18.0.0/16']) && !$A('172.19.0.1', ['172.18.0.0/16']));
check('a prefix that is not on a byte boundary (/20)', $A('10.0.15.255', ['10.0.0.0/20']) && !$A('10.0.16.0', ['10.0.0.0/20']));
check('/32 is one address, /0 is everything', $A('1.2.3.4', ['1.2.3.4/32']) && !$A('1.2.3.5', ['1.2.3.4/32']) && $A('8.8.8.8', ['0.0.0.0/0']));
check('a list: any entry will do', $A('192.168.1.9', ['10.0.1.5', '192.168.1.0/24']));
check('IPv6 address and network', $A('fd00::5', ['fd00::/8']) && $A('2001:db8::1', ['2001:db8::1']) && !$A('fe80::1', ['fd00::/8']));
check('IPv4 never matches an IPv6 entry and vice versa', !$A('10.0.1.5', ['fd00::/8']) && !$A('fd00::5', ['10.0.0.0/8']));
check('IPv4-mapped IPv6 counts as the IPv4 address', $A('::ffff:10.0.1.5', ['10.0.1.0/24']));
check('an empty list allows nobody (enforce() skips the check when the list is empty)', !$A('10.0.1.5', []));
check('garbage in the request address is refused', !$A('', ['0.0.0.0/0']) && !$A('not-an-ip', ['0.0.0.0/0']) && !$A('10.0.1.5; DROP', ['10.0.1.5']));
check('a malformed entry allows nothing (never everything)', !$A('10.0.1.5', ['10.0.1.0/33']) && !$A('10.0.1.5', ['10.0.1.0/abc']) && !$A('10.0.1.5', ['garbage']) && !$A('10.0.1.5', ['']));
check('spaces around entries are tolerated', $A('10.0.1.5', [' 10.0.1.5 ']));

echo "PeerGuard::refuses() / Config\n";
$list = ['10.0.1.5', '172.18.0.0/16'];
check('a web request from the proxy is let through, from another LAN host it is refused', !PeerGuard::refuses('fpm-fcgi', '10.0.1.5', $list) && PeerGuard::refuses('fpm-fcgi', '192.168.1.77', $list));
check('the CLI is never refused (cron jobs, tests)', !PeerGuard::refuses('cli', '203.0.113.9', $list));
check('without a list nothing is refused (the old behaviour)', !PeerGuard::refuses('fpm-fcgi', '203.0.113.9', []));
check('a request with no address at all is refused when a list is set', PeerGuard::refuses('apache2handler', '', $list));
putenv('TRUSTED_PROXIES=10.0.1.5, 172.18.0.0/16 ,');
Config::reset(); Config::load();
check('TRUSTED_PROXIES is read as a comma list; blanks are dropped', Config::trustedProxies() === $list, json_encode(Config::trustedProxies()));
putenv('TRUSTED_PROXIES');
Config::reset(); Config::load();
check('unset means no restriction', Config::trustedProxies() === []);
ob_start(); PeerGuard::enforce(); $o = ob_get_clean();
check('enforce() in the CLI does nothing', $o === '');

echo "route files and scripts\n";
$unguarded = [];
foreach (glob("$root/routes/*.php") as $f) {
    $head = substr(file_get_contents($f), 0, 200);
    if (!str_contains($head, "defined('SPARKSPEND_BOOTED')")) $unguarded[] = basename($f);
}
check('every route file refuses to run outside the bootstrap', $unguarded === [], implode(', ', $unguarded));
check('configuration.php sets the marker', str_contains(file_get_contents("$root/includes/configuration.php"), "define('SPARKSPEND_BOOTED', true)"));
$noCli = [];
$cronFiles = array_merge(glob("$root/cron/*.php"), glob("$root/cron/archive/*.php"), ["$root/migrate.php", "$root/doctor.php"]);
check("the scan finds the cron scripts (it would pass on an empty list)", count($cronFiles) >= 10);
foreach ($cronFiles as $f) {
    if (!preg_match("/PHP_SAPI\s*!==\s*'cli'/", file_get_contents($f))) $noCli[] = str_replace("$root/", '', $f);
}
check('every cron script (the archive of one-off fixes too), migrate.php and doctor.php are CLI-only (fetched over HTTP they would RUN)', $noCli === [], implode(', ', $noCli));
$entry = ['index.php', 'api.php'];
$missing = array_filter($entry, fn($f) => !str_contains(file_get_contents("$root/$f"), 'PeerGuard::enforce()'));
check('the UI and the API entry points enforce the peer guard', $missing === [], implode(', ', $missing));
foreach (['receive_vehicle_data.php', 'receive_charger_event.php', 'receive_device_energy.php', 'health.php'] as $f) {
    check("$f is a Bearer-key endpoint and does not use the peer guard", !str_contains(file_get_contents("$root/$f"), 'PeerGuard'));
}

echo "Exposure\n";
$cl = fn(string $p, int $s) => Exposure::classify($p, $s)['level'];
check('2xx on a secret path fails', $cl('/.env', 200) === 'fail' && $cl('/data/charging_data.db', 200) === 'fail' && $cl('/.git/config', 206) === 'fail');
check('401/403/404 is fine', $cl('/.env', 403) === 'ok' && $cl('/.env', 404) === 'ok' && $cl('/.env', 401) === 'ok');
check('a redirect is a warning (a login page tells nothing about the server)', $cl('/.env', 302) === 'warn');
check('a 500 is a warning: the script ran instead of being refused', $cl('/routes/get_vehicles.php', 500) === 'warn');
check('no answer is a warning, never a pass', $cl('/.env', 0) === 'warn');
check('the message names what leaks', str_contains(Exposure::classify('/.env', 200)['detail'], 'secrets'));
$all200 = Exposure::probe('http://x', null, fn($p) => 200);
check('a server that serves everything fails every secret path', count(array_filter($all200, fn($r) => $r['level'] === 'fail')) === count(Exposure::SECRET));
$closed = Exposure::probe('http://x', null, fn($p) => in_array($p, Exposure::PUBLIC, true) ? 200 : 404);
check('a server that serves only the public files passes', !array_filter($closed, fn($r) => $r['level'] !== 'ok') && count($closed) === count(Exposure::SECRET));
$login = Exposure::probe('http://x', null, fn($p) => 302);
check('a probe that only meets a login redirect says so instead of passing', count($login) === 1 && $login[0]['level'] === 'warn');
$paths = array_keys(Exposure::SECRET);
check('the probe never requests a script that would act when fetched', !array_filter($paths, fn($p) => preg_match('~^/(cron/[^.]+\.php|migrate\.php|doctor\.php|health\.php|receive_)~', $p)));
check('the probe list covers the secrets: .env, .git, both databases, the log', !array_diff(['/.env', '/.git/config', '/data/charging_data.db', '/data/powerlog_data.db', '/cron/cron.log'], $paths));

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
