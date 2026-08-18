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

// Estado seguro para componentes globais, disponível a qualquer usuário logado.
// Não expõe chave, prompt nem configurações do provedor.
if (isset($_GET['status'])) {
    $ai = wrcrm_get_ai_settings(false);
    echo json_encode([
        'success' => true,
        'ai' => [
            'enabled' => (int)($ai['enabled'] ?? 0),
            'proactive_enabled' => (int)($ai['proactive_enabled'] ?? 0),
            'proactive_interval_minutes' => (int)($ai['proactive_interval_minutes'] ?? 30),
            'draggable_launcher_enabled' => (int)($ai['draggable_launcher_enabled'] ?? 0),
        ],
    ]);
    exit;
}

if (!hasPermission('configuracoes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

echo json_encode(['success' => true, 'ai' => wrcrm_get_ai_settings(false)]);
