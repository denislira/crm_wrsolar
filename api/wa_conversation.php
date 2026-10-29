<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Não autorizado']); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/wa_baileys_api.php';
require_once __DIR__ . '/../includes/whatsapp_message_store.php';
function wa_conv_phone_variants(string $phone): array {
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    $variants = [$digits];
    if (str_starts_with($digits, '55') && strlen($digits) > 11) $variants[] = substr($digits, 2);
    elseif (strlen($digits) >= 10 && strlen($digits) <= 11) $variants[] = '55' . $digits;
    foreach ($variants as $variant) {
        $local = str_starts_with($variant, '55') ? substr($variant, 2) : $variant;
        if (strlen($local) === 11 && $local[2] === '9') {
            $withoutNine = substr($local, 0, 2) . substr($local, 3);
            $variants[] = $withoutNine;
            $variants[] = '55' . $withoutNine;
        } elseif (strlen($local) === 10 && in_array($local[2], ['6','7','8','9'], true)) {
            $withNine = substr($local, 0, 2) . '9' . substr($local, 2);
            $variants[] = $withNine;
            $variants[] = '55' . $withNine;
        }
    }
    return array_values(array_unique(array_filter($variants)));
}
$phone = preg_replace('/\D+/', '', (string)($_REQUEST['phone'] ?? '')) ?: '';
if (strlen($phone) < 10 || strlen($phone) > 15) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Telefone inválido']); exit; }
wa_messages_ensure_table($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $variants = wa_conv_phone_variants($phone);
    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $st = $pdo->prepare("SELECT id, direction, message_text, media_kind, media_mime, media_filename, media_path IS NOT NULL AS has_media, sent_at FROM whatsapp_messages WHERE phone_normalized IN ($placeholders) ORDER BY sent_at ASC, id ASC LIMIT 500"); $st->execute($variants);
    $messages = $st->fetchAll(PDO::FETCH_ASSOC);
    $messages = array_values(array_filter($messages, static function (array $message): bool {
        return trim((string)($message['message_text'] ?? '')) !== '[reactionMessage]';
    }));
    $legacyLabels = [
        '[imageMessage]' => 'Imagem antiga: o arquivo não foi armazenado',
        '[audioMessage]' => 'Áudio antigo: o arquivo não foi armazenado',
        '[videoMessage]' => 'Vídeo antigo: o arquivo não foi armazenado',
        '[documentMessage]' => 'Documento antigo: o arquivo não foi armazenado',
        '[stickerMessage]' => 'Figurinha antiga: o arquivo não foi armazenado',
    ];
    foreach ($messages as &$message) {
        $message['media_url'] = !empty($message['has_media']) ? 'api/wa_media.php?id=' . (int)$message['id'] : null;
        $message['media_name'] = $message['media_filename'];
        $legacyText = trim((string)($message['message_text'] ?? ''));
        if (isset($legacyLabels[$legacyText])) $message['message_text'] = $legacyLabels[$legacyText];
        unset($message['media_path'], $message['has_media']);
    }
    unset($message);
    echo json_encode(['success'=>true,'messages'=>$messages], JSON_UNESCAPED_UNICODE); exit;
}
$text = trim((string)($_POST['message'] ?? ''));
if ($text === '' || mb_strlen($text) > 4096) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Mensagem inválida']); exit; }
$cfg = wa_baileys_config();
$result = wa_baileys_request('POST', '/send', ['empresa_id'=>$cfg['empresa_id'],'empresa_token'=>$cfg['empresa_token'],'telefone'=>$phone,'mensagem'=>$text]);
if (empty($result['ok'])) { http_response_code(422); echo json_encode(['success'=>false,'message'=>$result['error'] ?? 'Falha ao enviar']); exit; }
$id = trim((string)($result['message_id'] ?? ('out_' . bin2hex(random_bytes(8)))));
$st = $pdo->prepare("INSERT IGNORE INTO whatsapp_messages (phone_normalized,message_id,direction,message_text) VALUES (?,?,'out',?)"); $st->execute([$phone,$id,$text]);
echo json_encode(['success'=>true,'message_id'=>$id], JSON_UNESCAPED_UNICODE);
