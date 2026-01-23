<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Não autorizado']); exit; }
$storage_dir = __DIR__ . '/../storage';
if (!is_dir($storage_dir)) mkdir($storage_dir, 0755, true);
$state_file = $storage_dir . '/wa_state.json';
// generate token (simulate the payload that Whaileys would provide)
$token = bin2hex(random_bytes(16));
$state = [
    'connected' => false,
    'qr_data' => 'whatsapp-qr:' . $token,
    'qr_generated_at' => date('c')
];
file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT));
$chart = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . urlencode($state['qr_data']) . '&chld=L|1';
echo json_encode(['success'=>true,'qr'=>$chart, 'message'=>'QR gerado. Leia pelo cliente Whaileys.']);
