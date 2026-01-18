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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$client_name = trim($_POST['client_name'] ?? '');
$address = trim($_POST['address'] ?? '');
$proposal_value = isset($_POST['proposal_value']) ? str_replace([',',' '], ['.',''], $_POST['proposal_value']) : 0;
$status = $_POST['status'] ?? 'Prospecção';
$contract = $_POST['contract'] ?? null;
$closed_date = $_POST['closed_date'] ?? null;

if (empty($client_name)) {
    echo json_encode(['success' => false, 'message' => 'Nome do cliente obrigatório']);
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE projetos SET client_name = ?, address = ?, proposal_value = ?, status = ?, contract = ?, closed_date = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
    $stmt->execute([$client_name, $address, $proposal_value, $status, $contract ?: null, $closed_date ?: null, $id, $_SESSION['user_id']]);
    echo json_encode(['success' => true, 'message' => 'Projeto atualizado com sucesso']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar projeto: ' . $e->getMessage()]);
}
?>
