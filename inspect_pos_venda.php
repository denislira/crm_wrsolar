<?php
require __DIR__ . '/includes/config.php';
$stmt = $pdo->query('SELECT id, client_name, created_at, warranty_end FROM pos_venda ORDER BY id DESC LIMIT 10');
foreach ($stmt as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
