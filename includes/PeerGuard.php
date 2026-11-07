<?php
/**
 * Defence in depth behind the auth proxy: with TRUSTED_PROXIES set, the web pages and the JSON API
 * answer only requests that arrive from the proxy's address.
 *
 * The app has no login of its own - Authentik in front of it is the login. That is only as strong as the
 * rule that nothing else can reach the web server: a request that goes straight to the server's address
 * on the internal network skips Authentik altogether (it was found open that way: the API, and also the
 * files in the web root). This closes that door from inside the app, whatever the web server is set up to
 * do. The Bearer-key endpoints (Home Assistant, the health check) have their own key and are not subject
 * to it.
 *
 *   TRUSTED_PROXIES=10.0.1.5,172.18.0.0/16        (comma separated IPs / CIDRs, IPv4 or IPv6)
 *
 * It looks at REMOTE_ADDR only - the address of the TCP peer - never at X-Forwarded-For, which anybody
 * can write. (If the web server rewrites REMOTE_ADDR to the real client's address with a real_ip setting,
 * list the real clients' networks instead.) Unset = no restriction, as before.
 */
final class PeerGuard
{
    /** @param string[] $allowed IPs and CIDRs */
    public static function allowed(string $remoteAddr, array $allowed): bool
    {
        $ip = self::pack($remoteAddr);
        if ($ip === null) return false;
        foreach ($allowed as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $netBin = self::pack($net);
            if ($netBin === null || strlen($netBin) !== strlen($ip)) continue;      // IPv4 never matches IPv6
            $max  = strlen($ip) * 8;
            $bits = $bits === null ? $max : (ctype_digit($bits) ? (int) $bits : -1);
            if ($bits < 0 || $bits > $max) continue;                                 // a bad entry allows nothing
            if (self::samePrefix($ip, $netBin, $bits)) return true;
        }
        return false;
    }

    /** The decision, pure: refuse a web request (not the CLI) from a peer outside a non-empty list. */
    public static function refuses(string $sapi, string $remoteAddr, array $allowed): bool
    {
        return $sapi !== 'cli' && $allowed !== [] && !self::allowed($remoteAddr, $allowed);
    }

    /** Answers 403 and ends the request when the peer is not allowed. Does nothing for the CLI or without a list. */
    public static function enforce(): void
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!self::refuses(PHP_SAPI, $remote, Config::trustedProxies())) return;
        error_log("PeerGuard: refused {$remote} (not in TRUSTED_PROXIES)");
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        exit;
    }

    /** Binary form of an address (4 or 16 bytes); IPv4-mapped IPv6 (::ffff:a.b.c.d) counts as IPv4. */
    private static function pack(?string $addr): ?string
    {
        if ($addr === null || $addr === '') return null;
        $bin = @inet_pton($addr);
        if ($bin === false) return null;
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) $bin = substr($bin, 12);
        return $bin;
    }

    private static function samePrefix(string $a, string $b, int $bits): bool
    {
        $full = intdiv($bits, 8);
        if ($full > 0 && substr($a, 0, $full) !== substr($b, 0, $full)) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }
}
