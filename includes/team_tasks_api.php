<?php
// API para CRUD de tarefas de equipe
require_once __DIR__ . '/config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error'=>'Não autenticado']);
    exit;
}
$userId = $_SESSION['user_id'];
header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $where = 'user_id = ?';
        $params = [$userId];
        if (!empty($_GET['equipe'])) {
            $where .= ' AND equipe = ?';
            $params[] = $_GET['equipe'];
        }
        if (!empty($_GET['status'])) {
            $where .= ' AND status = ?';
            $params[] = $_GET['status'];
        }
        $sql = "SELECT * FROM team_tasks WHERE $where ORDER BY data_vencimento ASC, criado_em DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'add':
        $data = json_decode(file_get_contents('php://input'), true);
        // force responsavel to current session username for security
        $responsavel = $_SESSION['username'] ?? '';
        $stmt = $pdo->prepare("INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, responsavel, data_vencimento) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $data_venc = (isset($data['data_vencimento']) && trim($data['data_vencimento']) !== '') ? $data['data_vencimento'] : null;
        $stmt->execute([
            $userId,
            $data['equipe'] ?? null,
            $data['titulo'] ?? null,
            $data['descricao'] ?? '',
            $data['status'] ?? 'Pendente',
            $responsavel,
            $data_venc
        ]);
        echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId()]);
        break;
    case 'update':
        $id = $_GET['id'] ?? 0;
        $data = json_decode(file_get_contents('php://input'), true);
        // Do not allow changing responsavel via API — keep existing responsavel
        $cur = $pdo->prepare("SELECT responsavel FROM team_tasks WHERE id = ? AND user_id = ?");
        $cur->execute([$id, $userId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        $responsavel = $row ? $row['responsavel'] : ($_SESSION['username'] ?? '');
        $stmt = $pdo->prepare("UPDATE team_tasks SET equipe=?, titulo=?, descricao=?, status=?, responsavel=?, data_vencimento=? WHERE id=? AND user_id=?");
        $data_venc = (isset($data['data_vencimento']) && trim($data['data_vencimento']) !== '') ? $data['data_vencimento'] : null;
        $stmt->execute([
            $data['equipe'] ?? null,
            $data['titulo'] ?? null,
            $data['descricao'] ?? '',
            $data['status'] ?? 'Pendente',
            $responsavel,
            $data_venc,
            $id,
            $userId
        ]);
        echo json_encode(['success'=>true]);
        break;
    case 'recent_activities':
        $sql = "SELECT id, equipe, titulo, responsavel, criado_em, atualizado_em FROM team_tasks WHERE user_id = ? AND (criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR atualizado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)) ORDER BY GREATEST(criado_em, atualizado_em) DESC LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $activities = [];
        foreach ($tasks as $t) {
            $isNew = $t['criado_em'] == $t['atualizado_em'];
            $timestamp = $isNew ? $t['criado_em'] : $t['atualizado_em'];
            $activities[] = [
                'type' => $isNew ? 'created' : 'updated',
                'titulo' => $t['titulo'],
                'equipe' => $t['equipe'],
                'responsavel' => $t['responsavel'],
                'timestamp' => $timestamp
            ];
        }
        echo json_encode($activities);
        break;
    case 'delete':
        $id = $_GET['id'] ?? 0;
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }
        $stmt = $pdo->prepare("DELETE FROM team_tasks WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Not found or no permission']);
        }
        break;
}
