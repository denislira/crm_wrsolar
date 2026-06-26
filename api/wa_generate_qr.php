<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

$storage_dir = __DIR__ . '/../storage';
if (!is_dir($storage_dir)) mkdir($storage_dir, 0755, true);
$state_file = $storage_dir . '/wa_state.json';

$state = ['connected' => false];
if (file_exists($state_file)) {
    $raw = @file_get_contents($state_file);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $state = $decoded;
}

if (!empty($state['connected'])) {
    echo json_encode([
        'success' => true,
        'connected' => true,
        'message' => 'WhatsApp ja esta conectado.'
    ]);
    exit;
}

$qrData = $state['qr_data'] ?? '';
$generatedAt = $state['qr_generated_at'] ?? null;
$isFakeQr = is_string($qrData) && strpos($qrData, 'whatsapp-qr:') === 0;

if ($isFakeQr) {
    unset($state['qr_data']);
    $state['info'] = 'QR antigo invalido removido. Inicie o wa-service para gerar um QR real do WhatsApp.';
    file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT));

    echo json_encode([
        'success' => false,
        'message' => 'O QR salvo era apenas um token de teste e foi removido. Inicie o servico Node em wa-service para gerar um QR real do WhatsApp.'
    ]);
    exit;
}

if (!empty($qrData)) {
    echo json_encode([
        'success' => true,
        'qr_available' => true,
        'qr_generated_at' => $generatedAt,
        'message' => 'QR real encontrado. Escaneie antes de expirar.'
    ]);
    exit;
}

$state['connected'] = false;
$state['info'] = 'Aguardando QR real do wa-service.';
file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT));

echo json_encode([
    'success' => false,
    'message' => 'Nenhum QR real disponivel. Abra um terminal em wa-service e rode npm start; quando o Baileys gerar o QR, ele aparecera aqui.'
]);
