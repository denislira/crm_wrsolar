<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Não autorizado']); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/wa_baileys_api.php';
function wa_conv_table(PDO $pdo): void { $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone_normalized VARCHAR(40) NOT NULL, message_id VARCHAR(255) DEFAULT NULL, direction ENUM('in','out') NOT NULL, message_text TEXT NOT NULL, sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY ux_whatsapp_message (phone_normalized,message_id), KEY idx_whatsapp_phone_date (phone_normalized,sent_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); }
$phone = preg_replace('/\D+/', '', (string)($_REQUEST['phone'] ?? '')) ?: '';
if (strlen($phone) < 10 || strlen($phone) > 15) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Telefone inválido']); exit; }
wa_conv_table($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare('SELECT id, direction, message_text, sent_at FROM whatsapp_messages WHERE phone_normalized = ? ORDER BY sent_at ASC, id ASC LIMIT 500'); $st->execute([$phone]);
    echo json_encode(['success'=>true,'messages'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
}
$text = trim((string)($_POST['message'] ?? ''));
if ($text === '' || mb_strlen($text) > 4096) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Mensagem inválida']); exit; }
$cfg = wa_baileys_config();
$result = wa_baileys_request('POST', '/send', ['empresa_id'=>$cfg['empresa_id'],'empresa_token'=>$cfg['empresa_token'],'telefone'=>$phone,'mensagem'=>$text]);
if (empty($result['ok'])) { http_response_code(422); echo json_encode(['success'=>false,'message'=>$result['error'] ?? 'Falha ao enviar']); exit; }
$id = trim((string)($result['message_id'] ?? ('out_' . bin2hex(random_bytes(8)))));
$st = $pdo->prepare("INSERT IGNORE INTO whatsapp_messages (phone_normalized,message_id,direction,message_text) VALUES (?,?,'out',?)"); $st->execute([$phone,$id,$text]);
echo json_encode(['success'=>true,'message_id'=>$id], JSON_UNESCAPED_UNICODE);
