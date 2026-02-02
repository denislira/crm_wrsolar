<?php
// Script web para alterar tipos de colunas em produção
require_once __DIR__ . '/includes/config.php';

$changes = [
    'consumo_cliente' => 'VARCHAR(40) NULL',
    'estimativa_projeto_kwh' => 'VARCHAR(40) NULL',
];

try {
    foreach ($changes as $col => $def) {
        $pdo->exec("ALTER TABLE leads MODIFY COLUMN `$col` $def");
        echo "Coluna '$col' alterada para $def.<br>";
    }
    echo "Alterações executadas com sucesso!";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
?>