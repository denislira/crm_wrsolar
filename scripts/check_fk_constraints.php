<?php
require 'includes/config.php';

echo "Verificando constraints da tabela leads:\n\n";

$sql = "SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'leads'
  AND REFERENCED_TABLE_NAME IS NOT NULL";

try {
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        echo "Nenhuma FK encontrada.\n";
    } else {
        foreach($rows as $row) {
            echo "FK: {$row['CONSTRAINT_NAME']}\n";
            echo "  Coluna: {$row['COLUMN_NAME']}\n";
            echo "  Referencia: {$row['REFERENCED_TABLE_NAME']}.{$row['REFERENCED_COLUMN_NAME']}\n\n";
        }
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
?>
