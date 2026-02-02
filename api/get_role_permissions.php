<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/permissions.php';

if (empty($_SESSION['user_id']) || !hasPermission('configuracoes')) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

$role_id = isset($_GET['role_id']) ? intval($_GET['role_id']) : 0;
if (!$role_id) {
    echo json_encode(['success' => false, 'message' => 'role_id inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT screen, allowed FROM role_permissions WHERE role_id = ?');
    $stmt->execute([$role_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allowed = [];
    foreach ($rows as $r) {
        $allowed[$r['screen']] = (int)$r['allowed'];
    }
    echo json_encode(['success' => true, 'allowed' => $allowed]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao buscar permissões.']);
}

?>
