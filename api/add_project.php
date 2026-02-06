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

$client_name = trim($_POST['client_name'] ?? '');
$address = trim($_POST['address'] ?? '');
$proposal_value = isset($_POST['proposal_value']) ? str_replace([',',' '], ['.',''], $_POST['proposal_value']) : 0;
$status = $_POST['status'] ?? 'Prospecção';
$lead_id = isset($_POST['lead_id']) ? intval($_POST['lead_id']) : null;
$closed_date = $_POST['closed_date'] ?? null;

if (empty($client_name)) {
    echo json_encode(['success' => false, 'message' => 'Nome do cliente obrigatório']);
    exit;
}

try {
    $stmt = $pdo->prepare('INSERT INTO projetos (user_id, client_name, address, proposal_value, status, lead_id, closed_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$_SESSION['user_id'], $client_name, $address, $proposal_value, $status, $lead_id, $closed_date ?: null]);
    echo json_encode(['success' => true, 'message' => 'Projeto criado com sucesso']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao criar projeto: ' . $e->getMessage()]);
}
?>
