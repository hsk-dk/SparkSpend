<?php
/**
 * Provider Repository
 *
 * CRUD operations for the providers table.
 */

class ProviderRepository {

    public static function getAll(PDO $db): array {
        $stmt = $db->prepare("SELECT id, providerName FROM providers ORDER BY providerName");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function insert(PDO $db, string $name): int {
        $stmt = $db->prepare("INSERT INTO providers (providerName) VALUES (?)");
        $stmt->execute([trim($name)]);
        return (int) $db->lastInsertId();
    }

    public static function update(PDO $db, int $id, string $name): bool {
        $stmt = $db->prepare("UPDATE providers SET providerName = ? WHERE id = ?");
        return $stmt->execute([trim($name), $id]);
    }

    public static function delete(PDO $db, int $id): bool {
        $stmt = $db->prepare("DELETE FROM providers WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public static function usageCount(PDO $db, int $id): int {
        $stmt = $db->prepare("SELECT COUNT(*) FROM ext_charges WHERE providerId = ?");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }
}
