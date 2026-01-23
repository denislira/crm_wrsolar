<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$storage_dir = __DIR__ . '/../storage';
$state_file = $storage_dir . '/wa_state.json';
if (file_exists($state_file)) {
    $s = json_decode(file_get_contents($state_file), true);
    $s['connected'] = false;
    unset($s['qr_data']);
    unset($s['info']);
    file_put_contents($state_file, json_encode($s, JSON_PRETTY_PRINT));
}
echo json_encode(['success'=>true,'message'=>'Desconectado com sucesso.']);
