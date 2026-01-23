<?php
// Script para adicionar novas colunas à tabela leads
require_once __DIR__ . '/../includes/config.php';

$columnsToAdd = [
    'vendedor' => 'VARCHAR(255) NULL',
    'ultimo_contato' => 'DATETIME NULL',
    'observacao' => 'TEXT NULL',
    'envio_proposta' => 'DATETIME NULL',
];

try {
    foreach ($columnsToAdd as $col => $def) {
        // Check if column exists
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = ?");
        $stmt->execute([$col]);
        if (!$stmt->fetch()) {
            // Add column
            $pdo->exec("ALTER TABLE leads ADD COLUMN `$col` $def");
            echo "Coluna '$col' adicionada com sucesso.<br>";
        } else {
            echo "Coluna '$col' já existe.<br>";
        }
    }
    echo "Script executado com sucesso!";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
?>