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

$stmt = $pdo->prepare('SELECT p.*, l.phone AS lead_phone, COALESCE(l.orcamento_value, p.proposal_value) AS proposal_value, COALESCE(l.estimativa_projeto_kwh, p.projeto) AS projeto FROM projetos p LEFT JOIN leads l ON l.id = p.lead_id AND l.user_id = p.user_id WHERE p.user_id = ? ORDER BY p.id DESC');
$stmt->execute([$_SESSION['user_id']]);
$projetos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'data' => $projetos]);
?>
