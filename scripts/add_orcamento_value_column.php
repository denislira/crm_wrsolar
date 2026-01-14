<?php
// Migration to add orcamento_value column to leads table
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo->exec("ALTER TABLE leads ADD COLUMN orcamento_value DECIMAL(12,2) DEFAULT 0.00 AFTER estimativa_projeto_kwh");
    echo "Column 'orcamento_value' added to leads table successfully.\n";
} catch (Exception $e) {
    echo "Error adding column: " . $e->getMessage() . "\n";
}
?>