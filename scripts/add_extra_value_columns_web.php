<?php
// Script web para adicionar colunas extras em produção
require_once __DIR__ . '/includes/config.php';

$columns = ['orcamento_value2', 'estimativa_projeto_kwh2'];

foreach ($columns as $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = ?");
    $stmt->execute([$col]);
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE leads ADD COLUMN `$col` VARCHAR(255) DEFAULT NULL");
        echo "Coluna adicionada: $col<br>";
    } else {
        echo "Coluna já existe: $col<br>";
    }
}
echo "Processo concluído.";
?>