<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/permissions.php';

try {
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT role_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $role_id = $row['role_id'] ?? null;

    if (!$role_id) {
        echo json_encode(['success' => false, 'message' => 'Role não encontrado']);
        exit;
    }

    $pstm = $pdo->prepare('SELECT screen FROM role_permissions WHERE role_id = ? AND allowed = 1');
    $pstm->execute([$role_id]);
    $perms = $pstm->fetchAll(PDO::FETCH_COLUMN);

    if (!is_array($perms)) $perms = [];

    // Normalize to simple array
    $_SESSION['permissions'] = array_values($perms);

    echo json_encode(['success' => true, 'count' => count($_SESSION['permissions']), 'permissions' => $_SESSION['permissions']]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao recarregar permissões: ' . $e->getMessage()]);
    exit;
}
