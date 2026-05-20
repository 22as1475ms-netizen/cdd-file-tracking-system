<?php
require __DIR__ . '/app/config/app.php';
require __DIR__ . '/app/config/database.php';

$migrationFile = __DIR__ . '/app/migrations/002-add-organizational-structure.sql';

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

$sql = file_get_contents($migrationFile);
$statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s && !str_starts_with($s, '--'));

foreach ($statements as $statement) {
    try {
        echo "Executing: " . substr($statement, 0, 60) . "...\n";
        $pdo->exec($statement);
    } catch (Exception $e) {
        echo "  ❌ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n✅ Migration completed!\n";
?>
