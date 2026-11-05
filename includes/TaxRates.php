<?php
/**
 * Loads and validates config/tax_rates.json: the date-versioned Danish electricity tax
 * (elafgift, full and elvarme rate), the yearly allowance taxed at the full rate, and VAT.
 *
 * These numbers change by law, usually once a year, so they live in a data file that can be
 * edited without touching PHP. A wrong rate silently produces wrong kr amounts everywhere, so
 * an invalid or missing file is an error (never a quiet fallback), and dev/test_config_rates.php
 * validates the file and checks that it covers the current year.
 */
final class TaxRates
{
    public const DEFAULT_FILE = __DIR__ . '/../config/tax_rates.json';

    /**
     * @return array{high: array<string,float>, low: array<string,float>, highKwhPerYear: float, vatFactor: float, version: string}
     * @throws RuntimeException when the file is missing or invalid
     */
    public static function load(?string $file = null): array
    {
        $file ??= self::DEFAULT_FILE;
        $raw = is_file($file) ? file_get_contents($file) : false;
        if ($raw === false) {
            throw new RuntimeException("Tax rate file not found: {$file}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Tax rate file is not valid JSON: {$file}");
        }
        $errors = self::validate($data);
        if ($errors) {
            throw new RuntimeException("Tax rate file {$file} is invalid: " . implode('; ', $errors));
        }
        $table = static function (array $rows): array {
            $out = [];
            foreach ($rows as $r) $out[$r['from']] = (float) $r['rate'];
            ksort($out);
            return $out;
        };
        return [
            'high'           => $table($data['elafgift_high']),
            'low'            => $table($data['elafgift_low']),
            'highKwhPerYear' => (float) $data['elvarme_high_kwh_per_year'],
            'vatFactor'      => (float) $data['vat_factor'],
            'version'        => substr(md5($raw), 0, 8),   // changes whenever the rates change (cache keys)
        ];
    }

    /** @return string[] human-readable problems; empty = valid */
    public static function validate(array $data): array
    {
        $errors = [];
        foreach (['elafgift_high', 'elafgift_low'] as $key) {
            $rows = $data[$key] ?? null;
            if (!is_array($rows) || !$rows) { $errors[] = "{$key} must be a non-empty list"; continue; }
            $prev = null;
            foreach ($rows as $i => $r) {
                $from = is_array($r) ? ($r['from'] ?? null) : null;
                $rate = is_array($r) ? ($r['rate'] ?? null) : null;
                if (!is_string($from) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $errors[] = "{$key}[{$i}].from must be a date YYYY-MM-DD"; continue;
                }
                if (!is_int($rate) && !is_float($rate)) { $errors[] = "{$key}[{$i}].rate must be a number"; continue; }
                if ($rate < 0 || $rate > 5) $errors[] = "{$key}[{$i}].rate {$rate} is outside 0..5 kr/kWh";
                if ($prev !== null && $from <= $prev) $errors[] = "{$key}[{$i}].from {$from} must be later than the row before ({$prev})";
                $prev = $from;
            }
        }
        $allow = $data['elvarme_high_kwh_per_year'] ?? null;
        if ((!is_int($allow) && !is_float($allow)) || $allow <= 0 || $allow > 100000) $errors[] = 'elvarme_high_kwh_per_year must be a positive number of kWh';
        $vat = $data['vat_factor'] ?? null;
        if ((!is_int($vat) && !is_float($vat)) || $vat < 1 || $vat > 2) $errors[] = 'vat_factor must be a number between 1 and 2 (1.25 = 25 % VAT)';
        return $errors;
    }
}
