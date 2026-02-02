<?php
// Script web para adicionar coluna 'importado' em produção
require_once __DIR__ . '/includes/config.php';

$col = 'importado';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = ?");
$stmt->execute([$col]);
if ($stmt->fetchColumn() == 0) {
    $pdo->exec("ALTER TABLE leads ADD COLUMN `$col` TINYINT(1) DEFAULT 0");
    echo "Coluna adicionada com sucesso: $col<br>";
} else {
    echo "Coluna já existe: $col<br>";
}
echo "Processo concluído.";
?>