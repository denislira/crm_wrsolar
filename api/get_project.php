<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';

if (!hasPermission('projetos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM projetos WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $_SESSION['user_id']]);
$proj = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proj) {
    echo json_encode(['success' => false, 'message' => 'Projeto não encontrado']);
    exit;
}

echo json_encode(['success' => true, 'data' => $proj]);
?>
