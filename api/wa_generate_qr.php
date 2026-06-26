<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

$root_dir = realpath(__DIR__ . '/..');
$storage_dir = $root_dir . DIRECTORY_SEPARATOR . 'storage';
$logs_dir = $root_dir . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($storage_dir)) mkdir($storage_dir, 0755, true);
if (!is_dir($logs_dir)) mkdir($logs_dir, 0755, true);

$state_file = $storage_dir . DIRECTORY_SEPARATOR . 'wa_state.json';
$command_file = $storage_dir . DIRECTORY_SEPARATOR . 'wa_command.json';

function read_wa_json($path, $fallback = []) {
    if (!file_exists($path)) return $fallback;
    $raw = @file_get_contents($path);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function wa_service_is_recent($state) {
    if (empty($state['service_heartbeat_at'])) return false;
    $heartbeat = strtotime($state['service_heartbeat_at']);
    return $heartbeat && (time() - $heartbeat) <= 25;
}

function start_wa_service($root_dir, $logs_dir) {
    $script = $root_dir . DIRECTORY_SEPARATOR . 'wa-service' . DIRECTORY_SEPARATOR . 'index.js';
    if (!file_exists($script)) return false;

    $log = $logs_dir . DIRECTORY_SEPARATOR . 'wa-service.log';

    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $cmd = 'start /B "" node ' . escapeshellarg($script) . ' > ' . escapeshellarg($log) . ' 2>&1';
        $handle = @popen($cmd, 'r');
        if (is_resource($handle)) {
            @pclose($handle);
            return true;
        }
        return false;
    }

    $cmd = 'node ' . escapeshellarg($script) . ' > ' . escapeshellarg($log) . ' 2>&1 &';
    $handle = @popen($cmd, 'r');
    if (is_resource($handle)) {
        @pclose($handle);
        return true;
    }
    return false;
}

$state = read_wa_json($state_file, ['connected' => false]);

if (!empty($state['connected'])) {
    echo json_encode([
        'success' => true,
        'connected' => true,
        'message' => 'WhatsApp ja esta conectado.'
    ]);
    exit;
}

$service_recent = wa_service_is_recent($state);
$started = false;
if (!$service_recent) {
    $started = start_wa_service($root_dir, $logs_dir);
}

$command = [
    'id' => bin2hex(random_bytes(8)),
    'action' => 'renew_qr',
    'created_at' => date('c'),
    'requested_by' => $_SESSION['user_id']
];
file_put_contents($command_file, json_encode($command, JSON_PRETTY_PRINT));

$state['connected'] = false;
unset($state['qr_data']);
$state['info'] = $started
    ? 'Servico Baileys iniciado. Gerando QR real...'
    : 'Solicitando novo QR real ao servico Baileys...';
$state['qr_requested_at'] = date('c');
file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT));

echo json_encode([
    'success' => true,
    'started_service' => $started,
    'service_running' => $service_recent || $started,
    'message' => 'Solicitacao enviada. Aguarde alguns segundos e o QR real sera exibido automaticamente.'
]);
