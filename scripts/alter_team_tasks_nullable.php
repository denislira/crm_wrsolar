<?php
// Migration helper: make 'equipe' and 'titulo' columns nullable
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo->exec("ALTER TABLE team_tasks MODIFY COLUMN equipe VARCHAR(50) DEFAULT NULL;");
    $pdo->exec("ALTER TABLE team_tasks MODIFY COLUMN titulo VARCHAR(255) DEFAULT NULL;");
    echo "Altered team_tasks: columns 'equipe' and 'titulo' are now NULLABLE.\n";
    exit(0);
} catch (Exception $e) {
    echo "Erro ao alterar tabela team_tasks: " . $e->getMessage() . "\n";
    exit(1);
}
