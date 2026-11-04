<?php
/** Small generic database helpers. */
final class Db
{
    /** Does a row with this primary key exist? Table names are whitelisted (never from input). */
    public static function rowExists(PDO $db, string $table, int $id): bool
    {
        if (!in_array($table, ['vehicles', 'providers', 'charges', 'ext_charges'], true)) {
            throw new InvalidArgumentException("rowExists: unknown table {$table}");
        }
        $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }
}
