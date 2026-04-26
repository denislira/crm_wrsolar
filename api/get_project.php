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

$stmt = $pdo->prepare('SELECT p.*, COALESCE(l.orcamento_value, p.proposal_value) AS proposal_value, COALESCE(l.estimativa_projeto_kwh, p.projeto) AS projeto, l.phone AS lead_phone FROM projetos p LEFT JOIN leads l ON l.id = p.lead_id WHERE p.id = ?');
$stmt->execute([$id]);
$proj = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proj) {
    echo json_encode(['success' => false, 'message' => 'Projeto não encontrado']);
    exit;
}

echo json_encode(['success' => true, 'data' => $proj]);
?>
