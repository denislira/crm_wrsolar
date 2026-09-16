<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/wa_baileys_api.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

$enabled = !empty($_POST['whatsapp_enabled']) && $_POST['whatsapp_enabled'] !== '0' ? 1 : 0;
$autoCreate = !empty($_POST['auto_create_leads']) && $_POST['auto_create_leads'] !== '0' ? 1 : 0;
$leadCaptureMode = (string)($_POST['lead_capture_mode'] ?? 'new_only');
if (!in_array($leadCaptureMode, ['new_only', 'closed_after_days'], true)) $leadCaptureMode = 'new_only';
$reopenAfterDays = max(1, min(3650, (int)($_POST['reopen_after_days'] ?? 30)));
$cfg = wa_baileys_config();

try {
    $stmt = $pdo->prepare('INSERT INTO whatsapp_integracao_config (id, api_url, internal_secret, empresa_id, empresa_token, enabled, auto_create_leads, lead_capture_mode, reopen_after_days) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), auto_create_leads = VALUES(auto_create_leads), lead_capture_mode = VALUES(lead_capture_mode), reopen_after_days = VALUES(reopen_after_days), updated_at = NOW()');
    $stmt->execute([
        $cfg['url'] ?? 'http://127.0.0.1:3001',
        $cfg['secret'] ?? '',
        (int)($cfg['empresa_id'] ?? 9999),
        $cfg['empresa_token'] ?? 'wrcrm-9999',
        $enabled,
        $autoCreate,
        $leadCaptureMode,
        $reopenAfterDays,
    ]);
    echo json_encode([
        'success' => true,
        'message' => 'Configuracao do WhatsApp salva.',
        'whatsapp_enabled' => (bool)$enabled,
        'auto_create_leads' => (bool)$autoCreate,
        'lead_capture_mode' => $leadCaptureMode,
        'reopen_after_days' => $reopenAfterDays,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar configuracao.']);
}
