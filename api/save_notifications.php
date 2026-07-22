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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$events = ['reminder_created', 'lead_created', 'task_created', 'lead_sale_completed', 'lead_stage_changed'];
$roles = ['creator', 'responsible'];
$notifications = wrcrm_default_notification_settings();

foreach ($events as $event) {
    $notifications['events'][$event] = isset($_POST['events'][$event]) ? 1 : 0;
    $notifications['recipients'][$event] = [];
    foreach ($roles as $role) {
        if (isset($_POST['recipients'][$event]) && is_array($_POST['recipients'][$event]) && in_array($role, $_POST['recipients'][$event], true)) {
            $notifications['recipients'][$event][] = $role;
        }
    }
}

$saleNamesRaw = trim($_POST['sale_stage_names'] ?? '');
$saleNames = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $saleNamesRaw))));
if ($saleNames) {
    $notifications['sale_stage_names'] = $saleNames;
}

$settings = wrcrm_read_settings();
$settings['notifications'] = $notifications;

if (wrcrm_write_settings($settings)) {
    echo json_encode(['success' => true, 'message' => 'Notificações salvas com sucesso', 'notifications' => $notifications]);
} else {
    echo json_encode(['success' => false, 'message' => 'Falha ao salvar notificações']);
}
