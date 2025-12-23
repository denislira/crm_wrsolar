<?php
require_once __DIR__ . '/../includes/config.php';
$stmt = $pdo->query('SELECT id, lead_id, from_status, to_status, changed_by, note, is_alert, created_at FROM lead_movements ORDER BY created_at DESC LIMIT 10');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";