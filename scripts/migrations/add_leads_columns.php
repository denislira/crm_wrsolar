<?php
// Run via browser or CLI. Protect with token: create migrations.secret with a token.
header('Content-Type: text/plain');
$secretFile = __DIR__ . '/migrations.secret';
$token = $_GET['token'] ?? null;
if (!file_exists($secretFile)) { echo "migrations.secret not found. Copy migrations.secret.sample and set a secret token.\n"; exit; }
$expected = trim(file_get_contents($secretFile));
if (!$expected || $token !== $expected) { http_response_code(403); echo "Forbidden: invalid token\n"; exit; }
require_once __DIR__ . '/../../includes/config.php';
$columnsToAdd = [
    'vendedor' => 'VARCHAR(255) NULL',
    'ultimo_contato' => 'DATETIME NULL',
    'observacao' => 'TEXT NULL',
    'envio_proposta' => 'DATETIME NULL',
    'forma_pagamento' => 'VARCHAR(255) NULL',
    'user_id_update' => 'INT NULL'
];
foreach ($columnsToAdd as $col => $def) {
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = ?");
        $stmt->execute([$col]);
        if (!$stmt->fetch()) {
            $sql = "ALTER TABLE leads ADD COLUMN `$col` $def";
            $pdo->exec($sql);
            echo "Added column $col\n";
        } else {
            echo "Column $col already exists\n";
        }
    } catch (Exception $e) {
        echo "Error adding $col: " . $e->getMessage() . "\n";
    }
}

echo "done\n";
