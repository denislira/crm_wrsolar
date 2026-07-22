<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';

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

$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);
$settingsPath = $storageDir . '/settings.json';

$settings = [];
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $settings = $raw ? json_decode($raw, true) : [];
}

$smtp = isset($settings['smtp']) && is_array($settings['smtp']) ? $settings['smtp'] : [];

// Read fields
$host = isset($_POST['host']) ? trim($_POST['host']) : '';
$port = isset($_POST['port']) ? intval($_POST['port']) : 0;
$secure = isset($_POST['secure']) ? trim($_POST['secure']) : '';
$user = isset($_POST['user']) ? trim($_POST['user']) : '';
$pass = isset($_POST['pass']) ? trim($_POST['pass']) : '';
$from_email = isset($_POST['from_email']) ? trim($_POST['from_email']) : '';
$from_name = isset($_POST['from_name']) ? trim($_POST['from_name']) : '';
$auth = isset($_POST['auth']) && ($_POST['auth'] === '1' || $_POST['auth'] === 'true' || $_POST['auth'] === 'on') ? 1 : 0;

if ($host !== '') $smtp['host'] = $host; else $smtp['host'] = '';
if ($port > 0) $smtp['port'] = $port; else $smtp['port'] = '';
$smtp['secure'] = in_array($secure, ['ssl','tls']) ? $secure : '';
$smtp['user'] = $user;
if ($pass !== '') {
    $smtp['pass'] = $pass;
}
$smtp['from_email'] = $from_email;
$smtp['from_name'] = $from_name;
$smtp['auth'] = $auth;

$settings['smtp'] = $smtp;

if (file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT))) {
    $safeSmtp = $smtp;
    if (!empty($safeSmtp['pass'])) $safeSmtp['pass'] = '';
    echo json_encode(['success' => true, 'message' => 'SMTP salvo com sucesso', 'smtp' => $safeSmtp]);
} else {
    echo json_encode(['success' => false, 'message' => 'Falha ao salvar configurações SMTP']);
}
