<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/wa_baileys_api.php'; header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$cfg=wa_baileys_config(); if (empty($cfg['enabled'])) { echo json_encode(['success'=>true,'message'=>'Integração WhatsApp já está desativada.']); exit; } $r=wa_baileys_request('POST','/disconnect',['empresa_id'=>$cfg['empresa_id'],'empresa_token'=>$cfg['empresa_token']]);
echo json_encode(empty($r['ok'])?['success'=>false,'message'=>$r['error']??'Falha ao desconectar']:['success'=>true,'message'=>'Desconectado com sucesso.']);
