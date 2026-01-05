<?php
require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
// pick a user
$userIdStmt = $pdo->query('SELECT id FROM users LIMIT 1'); $userId = $userIdStmt->fetchColumn() ?: 1;
// create lead
$ins = $pdo->prepare('INSERT INTO leads (user_id, name, email, phone, source, status, stage_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
$ins->execute([$userId, 'TaskTest Lead', 'tasktest@example.com', '11999990000', 'test', 'Novo', null]);
$leadId = $pdo->lastInsertId();
$_SESSION['user_id'] = $userId;
// prepare POST input for update_status to stage id 1 (Qualificado)
$_POST = ['action'=>'update_status','id'=>$leadId,'status'=>'Qualificado','stage_id'=>1];
$_REQUEST = array_merge($_REQUEST, $_POST);
ob_start(); include __DIR__ . '/../includes/leads_api.php'; $out = ob_get_clean();
echo "API response: $out\n";
// check tasks
$stmt = $pdo->prepare('SELECT id, titulo, criado_em FROM team_tasks WHERE user_id = ? ORDER BY id DESC LIMIT 5');
$stmt->execute([$userId]); $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($tasks, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";
