<?php
require_once __DIR__ . '/../includes/config.php';
try {
    $sql = file_get_contents(__DIR__ . '/../database/create_team_tasks.sql');
    $pdo->exec($sql);
    echo "Tabela team_tasks criada ou já existe.\n";
    exit(0);
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}
