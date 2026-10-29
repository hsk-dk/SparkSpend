<?php
/**
 * Vehicle <-> charge pairing rules.
 *
 * Pure functions (no database, no logging) so the rules can be unit-tested:
 *   php dev/test_vehicle_pairing.php
 *
 * pairingSource values, strongest first:
 *   manual     user corrected it in the GUI
 *   api        vehicleId supplied by Monta (Partner API)
 *   matched    unambiguous Home Assistant plug-in reading
 *   uncertain  best guess only: stale reading, or two cars plugged in close together
 *   default    legacy fallback (kept for old rows)
 *   legacy     rows from before pairingSource existed
 *   unknown    no usable evidence -> vehicleId is NULL, a human has to decide
 */
final class VehiclePairing {

    /** Confidence of a pairingSource. Higher = more trustworthy. */
    public static function rank(?string $source): int {
        return match ($source) {
            'manual'            => 5,
            'api'               => 4,
            'matched'           => 3,
            'uncertain'         => 2,
            'default', 'legacy' => 1,
            default             => 0,   // 'unknown', NULL
        };
    }

    /**
     * Which vehicle owns a charge, judged from Home Assistant plug-in readings.
     *
     * The cable is plugged into a car first (HA reports vehicleId + odometer at
     * that moment); Monta may start the charge hours later. The charge therefore
     * belongs to the vehicle plugged in MOST RECENTLY at or before the charge's
     * cablePluggedInAt (plus a small grace for clock/latency skew).
     *
     * There is deliberately NO default vehicle: without evidence the result is
     * vehicleId = null / 'unknown'. A silent fallback to "car 1" is what used to
     * attribute every unmatched charge to the same car.
     *
     * @param array<int,array{vehicleId:int,ts:int}> $window  readings, ascending by ts
     * @return array{vehicleId:?int,source:string,reason:?string}
     */
    public static function match(
        array $window,
        int $targetTime,
        int $graceAfterSec,
        int $maxLookbackSec,
        int $ambiguitySec
    ): array {
        $cutoff   = $targetTime + $graceAfterSec;
        $eligible = [];
        foreach ($window as $row) {
            if ($row['ts'] > $cutoff) break;          // ascending order -> done
            $eligible[] = $row;
        }

        if (empty($eligible)) {
            return ['vehicleId' => null, 'source' => 'unknown',
                    'reason' => 'no plug-in reading at/before the charge'];
        }

        $chosen = end($eligible);

        // Most recent reading from a DIFFERENT vehicle before the chosen one.
        $prevOtherTs = null;
        for ($i = count($eligible) - 2; $i >= 0; $i--) {
            if ($eligible[$i]['vehicleId'] !== $chosen['vehicleId']) {
                $prevOtherTs = $eligible[$i]['ts'];
                break;
            }
        }

        // A stale reading from days ago is not evidence.
        if (($targetTime - $chosen['ts']) > $maxLookbackSec) {
            $ageH = round(($targetTime - $chosen['ts']) / 3600, 1);
            return ['vehicleId' => $chosen['vehicleId'], 'source' => 'uncertain',
                    'reason' => "latest plug-in is {$ageH}h old (> max look-back)"];
        }

        // Both cars plugged in close together -> can't tell which one charged.
        if ($prevOtherTs !== null && ($chosen['ts'] - $prevOtherTs) <= $ambiguitySec) {
            return ['vehicleId' => $chosen['vehicleId'], 'source' => 'uncertain',
                    'reason' => "another vehicle plugged in within {$ambiguitySec}s before vehicle {$chosen['vehicleId']}"];
        }

        return ['vehicleId' => $chosen['vehicleId'], 'source' => 'matched', 'reason' => null];
    }

    /**
     * What to store when a charge that is already in the database is synced again.
     *
     * A re-sync only ever moves a pairing UP in confidence. A run with less
     * evidence (HA readings outside the look-back window, a reading that hasn't
     * arrived yet, ...) must not downgrade or flip a pairing that is already
     * settled - and a manual correction (rank 5) can never be overwritten.
     *
     * @return array{vehicleId:?int,source:string}
     */
    public static function resolve(
        ?int $existingVehicleId,
        ?string $existingSource,
        ?int $newVehicleId,
        string $newSource
    ): array {
        $hasExisting = $existingVehicleId !== null && $existingVehicleId > 0;
        if ($hasExisting && self::rank($newSource) <= self::rank($existingSource)) {
            return ['vehicleId' => $existingVehicleId, 'source' => $existingSource ?? 'legacy'];
        }
        return ['vehicleId' => $newVehicleId, 'source' => $newSource];
    }
}
