<?php
require 'includes/config.php';
$stmt = $pdo->query('DESCRIBE leads');
$rows = $stmt->fetchAll();
echo "Colunas da tabela leads:\n";
foreach($rows as $row) {
    echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>
