<?php
// Adicionar colunas extras para valores múltiplos: orcamento_value2, estimativa_projeto_kwh2
require_once __DIR__ . '/../includes/config.php';

$columns = ['orcamento_value2', 'estimativa_projeto_kwh2'];

foreach ($columns as $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = ?");
    $stmt->execute([$col]);
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE leads ADD COLUMN `$col` VARCHAR(40) DEFAULT NULL");
        echo "Coluna adicionada: $col\n";
    } else {
        echo "Coluna já existe: $col\n";
    }
}
echo "Processo concluído.\n";
?>