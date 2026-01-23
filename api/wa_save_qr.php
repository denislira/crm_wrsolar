<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
// require login? allow for local testing
//$user_ok = isset($_SESSION['user_id']);
//if (!$user_ok) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$storage_dir = __DIR__ . '/../storage';
if (!is_dir($storage_dir)) mkdir($storage_dir, 0755, true);
$state_file = $storage_dir . '/wa_state.json';
$qr_text = $_POST['qr_text'] ?? null;
$qr_url = $_POST['qr_url'] ?? null;
if (!$qr_text && !$qr_url) { echo json_encode(['success'=>false,'message'=>'Missing qr_text or qr_url']); exit; }
$state = ['connected' => false];
if (file_exists($state_file)) {
    $raw = @file_get_contents($state_file);
    $s = json_decode($raw, true);
    if (is_array($s)) $state = $s;
}
if ($qr_text) {
    $state['qr_data'] = $qr_text;
} elseif ($qr_url) {
    // store URL as qr_data; wa_status and wa_qr_image will use it
    $state['qr_data'] = $qr_url;
}
$state['qr_generated_at'] = date('c');
file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT));
echo json_encode(['success'=>true,'message'=>'QR salvo com sucesso']);
