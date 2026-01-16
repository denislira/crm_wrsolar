<?php
require_once __DIR__ . '/../includes/config.php';
try {
    $sql = file_get_contents(__DIR__ . '/../database/create_teams.sql');
    $pdo->exec($sql);
    echo "Tabela teams criada ou já existe.\n";
    exit(0);
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}
