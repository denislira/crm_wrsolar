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
$lead_id = isset($_POST['lead_id']) && $_POST['lead_id'] !== '' ? intval($_POST['lead_id']) : null;
$closed_date = $_POST['closed_date'] ?? null;
$client_status = $_POST['client_status'] ?? null; // 'Assinante' or 'Ex-Cliente'

// Debug log
error_log("add_project.php - Recebido lead_id: " . print_r($_POST['lead_id'] ?? 'NOT SET', true));
error_log("add_project.php - Processado lead_id: " . ($lead_id ?? 'NULL'));

if (empty($client_name)) {
    echo json_encode(['success' => false, 'message' => 'Nome do cliente obrigatório']);
    exit;
}

try {
    // ensure column exists for client_status (safe migration)
    try {
        $col = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projetos' AND COLUMN_NAME = 'client_status'");
        $col->execute();
        if (!$col->fetchColumn()) {
            $pdo->exec("ALTER TABLE projetos ADD COLUMN client_status VARCHAR(50) DEFAULT 'Assinante'");
        }
    } catch (Exception $e) { /* ignore migration errors */ }

    // include client_status if provided
    if ($client_status !== null) {
        $stmt = $pdo->prepare('INSERT INTO projetos (user_id, client_name, address, proposal_value, status, lead_id, closed_date, client_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$_SESSION['user_id'], $client_name, $address, $proposal_value, $status, $lead_id, $closed_date ?: null, $client_status]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO projetos (user_id, client_name, address, proposal_value, status, lead_id, closed_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$_SESSION['user_id'], $client_name, $address, $proposal_value, $status, $lead_id, $closed_date ?: null]);
    }
    echo json_encode(['success' => true, 'message' => 'Projeto criado com sucesso']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao criar projeto: ' . $e->getMessage()]);
}
?>
