<?php
// apply_migrations.php - simple helper to run SQL files from database/ (local dev only)
if (php_sapi_name() !== 'cli') {
    echo "Run from CLI: php scripts/apply_migrations.php\n"; exit;
}
require __DIR__ . '/../includes/config.php';
$files = glob(__DIR__ . '/../database/*.sql');
foreach ($files as $f) {
    echo "Applying: $f\n";
    $sql = file_get_contents($f);
    try {
        $pdo->exec($sql);
        echo " OK\n";
    } catch (Exception $e) {
        echo " Failed: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
