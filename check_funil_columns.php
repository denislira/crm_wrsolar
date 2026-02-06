<?php
require 'includes/config.php';
$stmt = $pdo->query('DESCRIBE funil_stages');
$rows = $stmt->fetchAll();
echo "Colunas da tabela funil_stages:\n";
foreach($rows as $row) {
    echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>
