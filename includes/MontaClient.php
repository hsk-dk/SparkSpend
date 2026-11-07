<?php
/** Monta's public API over curl: token + paged charges, with timeouts and a short back-off on 5xx. */
final class MontaClient implements MontaApi
{
    public const PER_PAGE = 100;
    public const MAX_PAGES = 200;     // 20,000 charges per run is the safety limit; the rest follows next run
    public const ATTEMPTS = 3;

    /** @param callable(string,string=):void $log */
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $authEndpoint,
        private string $dataEndpoint,
        private $log
    ) {}

    public function token(): string
    {
        [$body, $code] = $this->request($this->authEndpoint, [
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['clientId' => $this->clientId, 'clientSecret' => $this->clientSecret]),
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($code !== 200) throw new MontaApiException("Could not get an access token: HTTP {$code}", $code);
        $data = json_decode((string) $body, true);
        if (!is_array($data) || empty($data['accessToken'])) throw new MontaApiException('No accessToken in the answer');
        return (string) $data['accessToken'];
    }

    public function charges(string $token, string $fromDate, string $toDate): ?array
    {
        $all = []; $page = 0;
        while (true) {
            $url = $this->dataEndpoint . '?' . http_build_query(['fromDate' => $fromDate, 'toDate' => $toDate, 'page' => $page, 'perPage' => self::PER_PAGE]);
            $body = false; $code = 0; $err = '';
            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                [$body, $code, $err] = $this->request($url, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_TIMEOUT => 60]);
                $transient = $body === false || ($code >= 500 && $code <= 599);
                if (!$transient || $attempt === self::ATTEMPTS) break;
                $wait = 2 ** $attempt;
                ($this->log)("Transient error (HTTP {$code}" . ($err ? ", {$err}" : '') . ") on page {$page}, attempt {$attempt}/" . self::ATTEMPTS . ", waiting {$wait}s.", 'WARN');
                sleep($wait);
            }
            if ($body === false) { ($this->log)("Network error towards Monta on page {$page}: {$err} - waiting for the next run.", 'ERROR'); return null; }
            if ($code === 429)   { ($this->log)('Monta rate limit (429) - waiting for the next run.', 'WARN'); return null; }
            if ($code !== 200)   throw new MontaApiException("Fetching charges failed: HTTP {$code} (page {$page})", $code);

            $batch = (json_decode($body, true)['data'] ?? []);
            $all = array_merge($all, $batch);
            if (count($batch) < self::PER_PAGE) break;
            if (++$page > self::MAX_PAGES) { ($this->log)("Pagination limit reached (page {$page}) - the rest follows next run.", 'WARN'); break; }
        }
        if ($page > 0) ($this->log)('Fetched ' . count($all) . ' charges across ' . ($page + 1) . ' pages.');
        return $all;
    }

    /** @return array{0:string|false,1:int,2:string} body, HTTP code, curl error */
    private function request(string $url, array $options): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$body, $code, $err];
    }
}
