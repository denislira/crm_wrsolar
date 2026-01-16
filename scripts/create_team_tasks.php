<?php
require_once __DIR__ . '/../includes/config.php';

// NOTE: columns `equipe` and `titulo` are created as NULLABLE in the SQL file.
// If you are migrating an existing database and need to make these columns nullable,
// run the migration helper: scripts/alter_team_tasks_nullable.php
try {
    $sql = file_get_contents(__DIR__ . '/../database/create_team_tasks.sql');
    $pdo->exec($sql);
    echo "Tabela team_tasks criada ou já existe.\n";
    echo "If migrating an existing DB, consider running scripts/alter_team_tasks_nullable.php to allow NULLs for equipe/titulo.\n";
    exit(0);
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}
