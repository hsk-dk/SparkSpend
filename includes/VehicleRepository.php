<?php
/**
 * Vehicle Repository
 *
 * CRUD operations for the vehicles and vehicle_charges tables.
 */

class VehicleRepository {

    public static function getAll(PDO $db): array {
        $stmt = $db->prepare("SELECT id, vehicleName FROM vehicles ORDER BY vehicleName");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function getById(PDO $db, int $id): ?array {
        $stmt = $db->prepare("SELECT id, vehicleName FROM vehicles WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function update(PDO $db, int $id, string $name): bool {
        $stmt = $db->prepare("UPDATE vehicles SET vehicleName = ? WHERE id = ?");
        return $stmt->execute([trim($name), $id]);
    }

    /**
     * Insert vehicle if it doesn't exist; update vehicleName when a non-empty name is given.
     */
    public static function upsert(PDO $db, int $id, string $name = ''): void {
        $name = trim($name);
        if ($name !== '') {
            $db->prepare(
                "INSERT INTO vehicles (id, vehicleName) VALUES (?, ?)
                 ON CONFLICT(id) DO UPDATE SET vehicleName = excluded.vehicleName"
            )->execute([$id, $name]);
        } else {
            $db->prepare(
                "INSERT OR IGNORE INTO vehicles (id, vehicleName) VALUES (?, ?)"
            )->execute([$id, 'Køretøj ' . $id]);
        }
    }

    /**
     * Insert vehicle odometer reading into vehicle_charges table.
     */
    public static function insertOdometerReading(PDO $db, array $data): bool {
        $stmt = $db->prepare("INSERT INTO vehicle_charges (vehicleId, cablePluggedInAt, odometer) VALUES (?, ?, ?)");
        return $stmt->execute([
            $data['vehicleId'],
            $data['timestamp'],
            $data['odometer']
        ]);
    }
}
