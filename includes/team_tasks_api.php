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
        $stmt = $pdo->prepare("INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, responsavel, data_vencimento) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $data['equipe'] ?? '',
            $data['titulo'] ?? '',
            $data['descricao'] ?? '',
            $data['status'] ?? 'Pendente',
            $data['responsavel'] ?? '',
            $data['data_vencimento'] ?? null
        ]);
        echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId()]);
        break;
    case 'update':
        $id = $_GET['id'] ?? 0;
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE team_tasks SET equipe=?, titulo=?, descricao=?, status=?, responsavel=?, data_vencimento=? WHERE id=? AND user_id=?");
        $stmt->execute([
            $data['equipe'] ?? '',
            $data['titulo'] ?? '',
            $data['descricao'] ?? '',
            $data['status'] ?? 'Pendente',
            $data['responsavel'] ?? '',
            $data['data_vencimento'] ?? null,
            $id,
            $userId
        ]);
        echo json_encode(['success'=>true]);
        break;
    case 'delete':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM team_tasks WHERE id=? AND user_id=?");
        $stmt->execute([$id, $userId]);
        echo json_encode(['success'=>true]);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error'=>'Ação inválida']);
}
