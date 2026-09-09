<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/wa_baileys_api.php'; header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Nao autorizado']); exit; }
$cfg=wa_baileys_config(); $r=wa_baileys_request('GET','/qr',['empresa_id'=>$cfg['empresa_id'],'empresa_token'=>$cfg['empresa_token']]);
echo json_encode(empty($r['ok'])?['success'=>false,'message'=>$r['error']??'API indisponivel']:['success'=>true,'message'=>'QR solicitado. Aguarde alguns segundos.','connected'=>!empty($r['connected']),'qr_data_uri'=>$r['qr']??null]);
