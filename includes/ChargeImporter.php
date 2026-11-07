<?php
/**
 * Stores charges from Monta in the charges table: normalises timestamps to UTC, pairs each charge with a
 * car and inserts or updates. Was saveChargingData() and its helpers in cron/update_monta_data.php, with
 * globals for caches; now a class that takes the database, so it can be tested against a real (in-memory)
 * schema.
 */
final class ChargeImporter
{
    /** @var array<int,array{vehicleId:int,ts:int}>|null */
    private ?array $window = null;
    private ?int $vehicleCount = null;
    public int $inserted = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @param callable(string,string=):void $log */
    public function __construct(private PDO $db, private $log) {}

    /** Monta sends offsets (+01:00 / +02:00); everything is stored as ...Z, so string comparisons and SQLite date functions work. */
    public static function toUtcZ(?string $ts): ?string
    {
        if ($ts === null || $ts === '') return $ts;
        if (str_ends_with($ts, 'Z')) return $ts;
        $epoch = strtotime($ts);
        return $epoch === false ? $ts : gmdate('Y-m-d\TH:i:s\Z', $epoch);
    }

    /** All or nothing: a failure rolls the whole batch back and is rethrown. */
    public function import(array $charges): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($charges as $charge) $this->saveOne($charge);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function saveOne(array $c): void
    {
        if (!isset($c['id']) || (int) $c['id'] <= 0) {
            // Without an id SQLite would invent a new row on every run.
            $this->skipped++;
            ($this->log)('Skipped a charge without id: ' . substr(json_encode($c), 0, 200), 'WARN');
            return;
        }
        foreach (['createdAt', 'updatedAt', 'cablePluggedInAt', 'startedAt', 'stoppedAt'] as $k) {
            $c[$k] = self::toUtcZ($c[$k] ?? null);
        }

        // Vehicle: Monta's own field (partner API only), else the Home Assistant plug-in readings.
        $montaVehicleId = isset($c['vehicleId']) ? (int) $c['vehicleId'] : 0;
        if ($montaVehicleId > 0) {
            $vehicleId = $montaVehicleId; $source = 'api';
        } else {
            $pair = $this->pair($c['cablePluggedInAt'] ?: ($c['startedAt'] ?: ($c['createdAt'] ?: null)));
            $vehicleId = $pair['vehicleId']; $source = $pair['source'];
        }
        if ($vehicleId !== null && $vehicleId > 0) {
            $name = trim((string) ($c['vehicleName'] ?? $c['vehicle']['name'] ?? $c['vehicle']['displayName'] ?? ''));
            VehicleRepository::upsert($this->db, $vehicleId, $name);
        }

        $soc = (isset($c['soc']) && is_array($c['soc']) && isset($c['soc']['percentage'])) ? $c['soc']['percentage'] : null;

        $existing = $this->db->prepare("SELECT vehicleId, pairingSource FROM charges WHERE id = ?");
        $existing->execute([$c['id']]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        $f = fn(string $k) => $c[$k] ?? null;
        if ($row) {
            // A re-sync only moves a pairing UP in confidence; a manual correction is never overwritten.
            $res = VehiclePairing::resolve($row['vehicleId'] !== null ? (int) $row['vehicleId'] : null, $row['pairingSource'] ?? 'legacy', $vehicleId, $source);
            $this->db->prepare("UPDATE charges SET chargePointId = ?, updatedAt = ?, startedAt = ?, stoppedAt = ?, state = ?,
                    consumedKwh = ?, kwhLimit = ?, startMeterKwh = ?, endMeterKwh = ?, cost = ?, stopReason = ?,
                    socPercentage = ?, socLimit = ?, vehicleId = ?, pairingSource = ? WHERE id = ?")
                ->execute([$f('chargePointId'), $c['updatedAt'], $c['startedAt'], $c['stoppedAt'], $f('state'),
                    $f('consumedKwh'), $f('kwhLimit'), $f('startMeterKwh'), $f('endMeterKwh'), $f('cost'), $f('stopReason'),
                    $soc, $f('socLimit'), $res['vehicleId'], $res['source'], $c['id']]);
            $this->updated++;
        } else {
            $this->db->prepare("INSERT INTO charges (id, chargePointId, createdAt, updatedAt, cablePluggedInAt, startedAt, stoppedAt, state,
                    consumedKwh, kwhLimit, startMeterKwh, endMeterKwh, cost, stopReason, socPercentage, socLimit, vehicleId, pairingSource)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$c['id'], $f('chargePointId'), $c['createdAt'], $c['updatedAt'], $c['cablePluggedInAt'], $c['startedAt'], $c['stoppedAt'], $f('state'),
                    $f('consumedKwh'), $f('kwhLimit'), $f('startMeterKwh'), $f('endMeterKwh'), $f('cost'), $f('stopReason'),
                    $soc, $f('socLimit'), $vehicleId, $source]);
            $this->inserted++;
        }
    }

    /** @return array{vehicleId:?int,source:string} */
    private function pair(?string $at): array
    {
        $target = ($at !== null && $at !== '') ? strtotime($at) : false;
        if ($target === false) {
            ($this->log)('Pairing: missing or unparseable timestamp: ' . var_export($at, true), 'WARN');
            return ['vehicleId' => null, 'source' => 'unknown'];
        }
        $r = VehiclePairing::match($this->window(), $target, Config::pairingGraceAfterSec(), Config::pairingMaxLookbackSec(), Config::pairingAmbiguitySec());
        if ($r['reason'] !== null && $this->vehicleCount() > 1) {
            ($this->log)("Pairing: {$r['reason']} ({$at}) - {$r['source']}", 'WARN');
        }
        return ['vehicleId' => $r['vehicleId'], 'source' => $r['source']];
    }

    /** The recent plug-in readings, parsed once per run (not once per charge). */
    private function window(): array
    {
        if ($this->window === null) {
            $this->window = [];
            $rows = $this->db->query("SELECT vehicleId, cablePluggedInAt FROM vehicle_charges ORDER BY rowid DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if ($r['cablePluggedInAt'] === null) continue;
                $ts = strtotime($r['cablePluggedInAt']);
                if ($ts !== false) $this->window[] = ['vehicleId' => (int) $r['vehicleId'], 'ts' => $ts];
            }
            usort($this->window, fn($a, $b) => $a['ts'] <=> $b['ts']);
        }
        return $this->window;
    }

    private function vehicleCount(): int
    {
        return $this->vehicleCount ??= (int) ($this->db->query("SELECT COUNT(*) FROM vehicles")->fetchColumn() ?: 1);
    }
}
