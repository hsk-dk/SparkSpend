<?php
/**
 * Migration 006: a vehicleId stored as BLOB/TEXT (found in production) becomes an INTEGER so that
 * `vehicleId = 2` and the join to vehicles match it.
 *
 * Run:  php dev/test_vehicleid_migration.php
 */
$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if ($ok) { echo "  ok   $name\n"; return; }
    $fails++; echo "  FAIL $name $info\n";
}

$mig = require __DIR__ . '/../migrations/charges/006_fix_vehicleid_storage_type.php';
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE charges (id TEXT PRIMARY KEY, vehicleId)');   // no column affinity, like the legacy rows
$ins = $db->prepare('INSERT INTO charges (id, vehicleId) VALUES (?, ?)');
$ins->bindValue(1, 'blob'); $ins->bindValue(2, '2', PDO::PARAM_LOB); $ins->execute();
$ins->execute(['text', '3']);
$ins->bindValue(1, 'int'); $ins->bindValue(2, 1, PDO::PARAM_INT); $ins->execute();
$ins->execute(['junk', 'abc']);
$ins->bindValue(1, 'null'); $ins->bindValue(2, null, PDO::PARAM_NULL); $ins->execute();

check('before: the blob does not match vehicleId = 2', (int) $db->query("SELECT COUNT(*) FROM charges WHERE id='blob' AND vehicleId = 2")->fetchColumn() === 0);
foreach ($mig['up'] as $sql) $db->exec($sql);
$by = []; foreach ($db->query("SELECT id, typeof(vehicleId) t, vehicleId v FROM charges", PDO::FETCH_ASSOC) as $r) $by[$r['id']] = $r;
check('the blob is now the integer 2', $by['blob']['t'] === 'integer' && (int) $by['blob']['v'] === 2);
check('the text "3" is now the integer 3', $by['text']['t'] === 'integer' && (int) $by['text']['v'] === 3);
check('an integer is untouched', $by['int']['t'] === 'integer' && (int) $by['int']['v'] === 1);
check('non-numeric text is left alone (not turned into 0)', $by['junk']['t'] === 'text' && $by['junk']['v'] === 'abc');
check('NULL stays NULL', $by['null']['t'] === 'null');
check('it matches vehicleId = 2 afterwards', (int) $db->query("SELECT COUNT(*) FROM charges WHERE id='blob' AND vehicleId = 2")->fetchColumn() === 1);

echo "\n$n checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
