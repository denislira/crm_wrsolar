<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Não autenticado']); exit; }
$section = (string)($_GET['section'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(25, max(5, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;
$username = (string)($_SESSION['username'] ?? '');
if ($username === '') {
    try { $u = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1'); $u->execute([$userId]); $username = (string)$u->fetchColumn(); } catch (Throwable $e) { }
}

$queries = [
 'leads' => ['SELECT id,user_id,name,email,phone,status,source,created_at FROM leads WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?', [$userId]],
 'projects' => ['SELECT id,user_id,client_name,proposal_value,status,created_at FROM projetos WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?', [$userId]],
 'movements' => ['SELECT id,lead_id,user_id,from_stage_id,to_stage_id,from_status,to_status,changed_by,note,is_alert,created_at FROM lead_movements WHERE user_id = ? OR changed_by = ? ORDER BY created_at DESC LIMIT ? OFFSET ?', [$userId,$username]],
 'reminders' => ['SELECT r.id,r.lead_id,r.message,r.remind_at,r.status,r.created_at,l.name AS lead_name FROM reminders r LEFT JOIN leads l ON l.id=r.lead_id WHERE r.created_by = ? ORDER BY r.created_at DESC LIMIT ? OFFSET ?', [$userId]],
 'tasks' => ['SELECT * FROM team_tasks WHERE user_id = ? OR responsavel_id = ? OR responsavel = ? ORDER BY data_vencimento ASC, criado_em DESC LIMIT ? OFFSET ?', [$userId,$userId,$username]],
];
if (!isset($queries[$section])) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Seção inválida']); exit; }
try {
    [$sql,$params] = $queries[$section];
    // LIMIT/OFFSET são números já validados; interpolá-los evita incompatibilidade
    // de alguns drivers MySQL que não aceitam bind nesses dois trechos.
    $sql = str_replace('LIMIT ? OFFSET ?', 'LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, $sql);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($items) > $limit;
    if ($hasMore) array_pop($items);
    echo json_encode(['success'=>true,'section'=>$section,'items'=>$items,'page'=>$page,'limit'=>$limit,'has_more'=>$hasMore], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['success'=>false,'message'=>'Não foi possível carregar esta seção'], JSON_UNESCAPED_UNICODE);
}
