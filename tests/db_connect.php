<?php
// Teste rápido de conexão PDO
require_once __DIR__ . '/../includes/config.php';
try {
    $res = $pdo->query('SELECT 1')->fetchColumn();
    echo "DB OK: " . ($res ? '1' : '0');
} catch (Exception $e) {
    echo "DB ERROR: " . $e->getMessage();
}
