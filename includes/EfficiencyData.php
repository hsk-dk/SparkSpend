<?php
/**
 * Reads what EfficiencyCalc needs: the odometer readings and the charges of each vehicle. Shared by the Effektivitet
 * route and the dashboard's EV card, so both count the same km and the same kWh.
 *
 * ALL history is read (not just a range): a drive that ends inside a range may start before it, and needs the reading
 * before the range.
 */
final class EfficiencyData
{
    /**
     * @return array{0: array<int,list<array{ts:int,odometer:float}>>, 1: array<int,list<array{ts:int,kwh:float,cost:?float}>>}
     *         [readings by vehicle, charges by vehicle]
     */
    public static function load(PDO $db, ?int $vehicleFilter = null): array
    {
        $vehicleSql = $vehicleFilter !== null ? ' AND vehicleId = ?' : '';
        $vehicleArg = $vehicleFilter !== null ? [$vehicleFilter] : [];

        $stmt = $db->prepare("
            SELECT vehicleId, cablePluggedInAt, odometer FROM vehicle_charges
            WHERE vehicleId IS NOT NULL AND odometer IS NOT NULL $vehicleSql
            ORDER BY vehicleId, cablePluggedInAt
        ");
        $stmt->execute($vehicleArg);
        $readingsByVehicle = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ts = strtotime((string) $r['cablePluggedInAt']);
            if ($ts === false) continue;
            $readingsByVehicle[(int) $r['vehicleId']][] = ['ts' => $ts, 'odometer' => (float) $r['odometer']];
        }

        // Charges per vehicle, timed by when they ended. Ongoing sessions (no stoppedAt) are
        // not counted until they are finished: their energy is still partial.
        $stmt = $db->prepare("
            SELECT vehicleId, stoppedAt AS datetime, consumedKwh AS kwh, cost AS pris FROM charges
            WHERE vehicleId IS NOT NULL AND stoppedAt IS NOT NULL AND consumedKwh > 0 $vehicleSql
        ");
        $stmt->execute($vehicleArg);
        $chargesByVehicle = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ts = strtotime((string) $r['datetime']);
            if ($ts === false) continue;
            $chargesByVehicle[(int) $r['vehicleId']][] = ['ts' => $ts, 'kwh' => (float) $r['kwh'], 'cost' => $r['pris'] !== null ? (float) $r['pris'] : null];
        }
        foreach (ChargeRepository::getExternal($db, null, null, $vehicleFilter, false) as $r) {
            $ts = strtotime((string) ($r['datetime'] ?? ''));
            if ($ts === false || empty($r['vehicleId']) || (float) $r['kwh'] <= 0) continue;
            $chargesByVehicle[(int) $r['vehicleId']][] = ['ts' => $ts, 'kwh' => (float) $r['kwh'], 'cost' => isset($r['pris']) ? (float) $r['pris'] : null];
        }

        return [$readingsByVehicle, $chargesByVehicle];
    }

    /**
     * Km, kWh and kr per km over the last $days days, all vehicles together (the intervals of every vehicle that END
     * in the window). Null when there is nothing to say.
     */
    public static function rollingAll(PDO $db, int $now, int $days = 30): ?array
    {
        [$readings, $charges] = self::load($db);
        $intervals = [];
        foreach ($readings as $vehicleId => $r) {
            $calc = EfficiencyCalc::intervals($r, $charges[$vehicleId] ?? []);
            $intervals = array_merge($intervals, EfficiencyCalc::rolling($calc['intervals'], $now, $days));
        }
        if ($intervals === []) return null;
        $m = EfficiencyCalc::metrics($intervals);
        return ($m['km'] ?? 0) > 0 ? $m : null;
    }
}
