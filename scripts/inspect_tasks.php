<?php
require_once __DIR__ . '/../includes/config.php';
$stmt = $pdo->query('SELECT id, user_id, equipe, titulo, criado_em FROM team_tasks ORDER BY id DESC LIMIT 10');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
