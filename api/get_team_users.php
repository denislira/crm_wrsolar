<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/permissions.php';

if (!hasPermission('configuracoes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$teamId = isset($_GET['team_id']) && is_numeric($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if ($teamId <= 0) {
    echo json_encode(['success' => false, 'message' => 'team_id inválido']);
    exit;
}

try {
    $teamStmt = $pdo->prepare('SELECT id, name FROM teams WHERE id = ? LIMIT 1');
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        echo json_encode(['success' => false, 'message' => 'Equipe não encontrada']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, username, nome_completo, email, avatar FROM users WHERE team_id = ? ORDER BY username');
    $stmt->execute([$teamId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'team' => $team, 'users' => $users]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
