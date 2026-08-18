<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/ai_settings.php';

if (!hasPermission('configuracoes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$result = wrcrm_call_ai_chat([
    ['role' => 'system', 'content' => 'Responda em portugues do Brasil, de forma curta.'],
    ['role' => 'user', 'content' => 'Teste de conexão do WRCRM. Responda apenas: IA conectada.'],
]);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Falha no teste da IA']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'IA conectada com sucesso', 'response' => $result['content']]);

