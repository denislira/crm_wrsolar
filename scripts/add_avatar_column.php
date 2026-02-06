<?php
// Script to add 'avatar' column to users table if missing
require_once __DIR__ . '/../includes/config.php';
$col = 'avatar';
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
    $stmt->execute([$col]);
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) DEFAULT NULL");
        echo "Coluna adicionada: $col\n";
    } else {
        echo "Coluna já existe: $col\n";
    }
} catch (Exception $e) {
    echo "Erro ao alterar tabela: " . $e->getMessage() . "\n";
}

echo "Processo finalizado.\n";
