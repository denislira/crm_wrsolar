<?php
// Script para adicionar coluna data_inicial (datetime) na tabela leads, se não existir
// Uso: php scripts/add_data_inicial_column.php

require_once __DIR__ . '/../includes/config.php';

$col = 'data_inicial';
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = ?");
    $stmt->execute([$col]);
    if ($stmt->fetchColumn() == 0) {
        // Adiciona a coluna como NULLABLE para não quebrar dados existentes
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `data_inicial` DATETIME DEFAULT NULL");
        echo "Coluna adicionada: $col\n";
    } else {
        echo "Coluna já existe: $col\n";
    }
} catch (Exception $e) {
    echo "Erro ao alterar tabela: " . $e->getMessage() . "\n";
}

echo "Processo finalizado.\n";
