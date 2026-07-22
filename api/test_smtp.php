<?php
header('Content-Type: application/json');
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/email_notifications.php';

if (!hasPermission('configuracoes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$to = trim($_POST['to'] ?? '');
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Informe um email válido para teste']);
    exit;
}

$html = '<p>Teste de SMTP do WRCRM realizado em ' . htmlspecialchars(date('d/m/Y H:i:s')) . '.</p>';
$sent = wrcrm_send_email($to, 'Teste de SMTP - WRCRM', $html);
echo json_encode([
    'success' => (bool)$sent,
    'message' => $sent ? 'Email de teste enviado' : 'Não foi possível enviar o email de teste'
]);
