<?php
/**
 * Validation of the user-supplied fields of an external charge (shared by the create and
 * update routes): kWh 0..1000, price 0..50000, and a date that is stored as UTC.
 * Every failure ends the request with HTTP 400 (see Http::fail).
 */
require_once __DIR__ . '/Http.php';

final class ChargeInput
{
    /**
     * @param array  $in         request body
     * @param string $dateKey    key holding the date/time ('chargeDateTime' on create, 'datetime' on update)
     * @param string $dateError  message for an unparsable date
     * @return array{kwh: float, pris: float, date: string}  date as 'Y-m-d\TH:i:00\Z' (UTC)
     */
    public static function validate(array $in, string $dateKey, string $dateError): array
    {
        $kwh  = floatval($in['kwh']  ?? 0);
        $pris = floatval($in['pris'] ?? 0);
        if (!is_numeric($in['kwh'] ?? '') || $kwh <= 0 || $kwh > 1000 || !is_finite($kwh)) {
            Http::fail('kwh skal være et tal mellem 0 og 1000');
        }
        if (!is_numeric($in['pris'] ?? '') || $pris < 0 || $pris > 50000 || !is_finite($pris)) {
            Http::fail('pris skal være et tal mellem 0 og 50000');
        }
        try {
            $dt = new DateTime((string) $in[$dateKey]);
            $dt->setTimezone(new DateTimeZone('UTC'));
            $date = $dt->format('Y-m-d\TH:i:00\Z');
        } catch (\Throwable $e) {
            Http::fail($dateError);
        }
        return ['kwh' => $kwh, 'pris' => $pris, 'date' => $date];
    }
}
