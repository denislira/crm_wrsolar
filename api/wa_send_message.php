<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/wa_baileys_api.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Não autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Método não permitido']); exit; }
$phone = trim((string)($_POST['phone'] ?? '')); $text = trim((string)($_POST['message'] ?? '')); $digits = preg_replace('/\D+/', '', $phone);
if (strlen($digits) < 10 || strlen($digits) > 15) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Informe o número com código do país. Exemplo: 5511999999999']); exit; }
if ($text === '' || mb_strlen($text) > 4096) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'A mensagem deve ter entre 1 e 4096 caracteres.']); exit; }
 $cfg = wa_baileys_config();
 if (empty($cfg['enabled'])) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Integração WhatsApp desativada.']); exit; }
 $result = wa_baileys_request('POST', '/send', ['empresa_id'=>$cfg['empresa_id'], 'empresa_token'=>$cfg['empresa_token'], 'telefone'=>$digits, 'mensagem'=>$text]);
 if (empty($result['ok'])) { http_response_code(($result['_http'] ?? 0) >= 500 ? 503 : 422); echo json_encode(['success'=>false,'message'=>$result['error'] ?? 'Falha ao enviar a mensagem.']); exit; }
 echo json_encode(['success'=>true,'message'=>'Mensagem enviada pelo WhatsApp.','message_id'=>$result['message_id'] ?? null]); exit;
$commandPath = $storage . '/wa_command.json'; $resultPath = $storage . '/wa_result.json'; $id = bin2hex(random_bytes(12)); @unlink($resultPath);
$command = ['id'=>$id,'action'=>'send_text','phone'=>$digits,'text'=>$text,'requested_by'=>(int)$_SESSION['user_id'],'created_at'=>date('c')];
if (@file_put_contents($commandPath, json_encode($command, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX) === false) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Não foi possível enviar o comando ao serviço WhatsApp.']); exit; }
$deadline = microtime(true) + 25; $result = null;
while (microtime(true) < $deadline) { if (is_file($resultPath)) { $candidate=json_decode((string)@file_get_contents($resultPath),true); if(is_array($candidate)&&($candidate['id']??'')===$id){$result=$candidate;break;} } usleep(250000); }
if (!$result) { http_response_code(504); echo json_encode(['success'=>false,'message'=>'O serviço WhatsApp não respondeu. Verifique se o wa-service está em execução.']); exit; }
if (empty($result['success'])) { http_response_code(422); echo json_encode(['success'=>false,'message'=>$result['error'] ?? 'Falha ao enviar a mensagem.']); exit; }
echo json_encode(['success'=>true,'message'=>'Mensagem enviada pelo WhatsApp.','message_id'=>$result['message_id'] ?? null]);
