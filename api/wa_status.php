<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/wa_baileys_api.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$cfg = wa_baileys_config();
if (empty($cfg['enabled'])) { echo json_encode(['success'=>true,'connected'=>false,'info'=>'Integração WhatsApp desativada','qr_data_uri'=>null,'whatsapp_enabled'=>false,'auto_create_leads'=>!empty($cfg['auto_create_leads']), 'lead_capture_mode'=>$cfg['lead_capture_mode'] ?? 'new_only', 'reopen_after_days'=>(int)($cfg['reopen_after_days'] ?? 30)]); exit; }
$r = wa_baileys_request('GET', '/qr', ['empresa_id'=>$cfg['empresa_id']]);
if (empty($r['ok'])) { echo json_encode(['success'=>false,'connected'=>false,'info'=>$r['error'] ?? 'API indisponivel']); exit; }
echo json_encode(['success'=>true,'connected'=>!empty($r['connected']), 'info'=>!empty($r['connected'])?'Conectado':'Aguardando QR', 'qr_data_uri'=>$r['qr'] ?? null, 'whatsapp_enabled'=>!empty($cfg['enabled']), 'auto_create_leads'=>!empty($cfg['auto_create_leads']), 'lead_capture_mode'=>$cfg['lead_capture_mode'] ?? 'new_only', 'reopen_after_days'=>(int)($cfg['reopen_after_days'] ?? 30)]);
