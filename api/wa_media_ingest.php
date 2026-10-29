<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/wa_baileys_api.php';
require_once __DIR__ . '/../includes/whatsapp_message_store.php';

header('Content-Type: application/json; charset=utf-8');
function wa_media_ingest_reply(array $body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = wa_baileys_config();
$secret = (string)($cfg['secret'] ?? '');
if ($secret !== '' && !hash_equals($secret, (string)($_SERVER['HTTP_X_INTERNAL_SECRET'] ?? ''))) {
    wa_media_ingest_reply(['success' => false, 'message' => 'unauthorized'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wa_media_ingest_reply(['success' => false, 'message' => 'method_not_allowed'], 405);
}

$phone = preg_replace('/\D+/', '', (string)($_SERVER['HTTP_X_WA_PHONE'] ?? '')) ?: '';
$messageId = trim((string)($_SERVER['HTTP_X_WA_MESSAGE_ID'] ?? ''));
$kind = (string)($_SERVER['HTTP_X_WA_KIND'] ?? '');
$mime = strtolower(trim(explode(';', (string)($_SERVER['HTTP_X_WA_MIME'] ?? ''))[0]));
$originalName = trim((string)($_SERVER['HTTP_X_WA_FILENAME'] ?? ''));
if (strlen($phone) < 10 || strlen($phone) > 15 || $messageId === '' || !in_array($kind, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
    wa_media_ingest_reply(['success' => false, 'message' => 'invalid_media_metadata'], 422);
}

$extensions = [
    'image' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'],
    'audio' => ['audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'application/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr', 'audio/3gpp' => '3gp', 'audio/wav' => 'wav', 'audio/webm' => 'webm'],
    'video' => ['video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'image/gif' => 'gif'],
    'document' => ['application/pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/zip' => 'zip', 'application/x-7z-compressed' => '7z', 'application/octet-stream' => 'bin'],
    'sticker' => ['image/webp' => 'webp'],
];
if (!isset($extensions[$kind][$mime])) {
    wa_media_ingest_reply(['success' => false, 'message' => 'unsupported_media_type'], 415);
}

$maxBytes = 7 * 1024 * 1024;
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > $maxBytes) wa_media_ingest_reply(['success' => false, 'message' => 'media_too_large'], 413);
$body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
if ($body === false || $body === '' || strlen($body) > $maxBytes) {
    wa_media_ingest_reply(['success' => false, 'message' => 'invalid_media_body'], 422);
}

if ($kind === 'image' || $kind === 'sticker') {
    $imageInfo = @getimagesizefromstring($body);
    if (!$imageInfo || ($imageInfo['mime'] ?? '') !== $mime || ($kind === 'sticker' && $mime !== 'image/webp')) {
        wa_media_ingest_reply(['success' => false, 'message' => 'invalid_image'], 415);
    }
} elseif ($kind === 'audio') {
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($body);
    $audioSignatures = ['audio/ogg' => ['audio/ogg', 'application/ogg'], 'audio/opus' => ['audio/ogg', 'application/ogg'], 'application/ogg' => ['audio/ogg', 'application/ogg'], 'audio/mpeg' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], 'audio/mp4' => ['audio/mp4', 'video/mp4', 'application/octet-stream'], 'audio/aac' => ['audio/aac', 'audio/x-hx-aac-adts', 'application/octet-stream'], 'audio/amr' => ['audio/amr', 'application/octet-stream'], 'audio/3gpp' => ['audio/3gpp', 'video/3gpp', 'application/octet-stream'], 'audio/wav' => ['audio/wav', 'audio/x-wav', 'application/octet-stream'], 'audio/webm' => ['audio/webm', 'application/octet-stream']];
    if (!in_array($detectedMime, $audioSignatures[$mime] ?? [], true)) {
        wa_media_ingest_reply(['success' => false, 'message' => 'invalid_audio'], 415);
    }
} elseif ($kind === 'video' && $mime === 'image/gif') {
    if (!@getimagesizefromstring($body)) wa_media_ingest_reply(['success' => false, 'message' => 'invalid_video'], 415);
} else {
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($body);
    $documentSignatures = ['application/pdf' => ['application/pdf'], 'application/msword' => ['application/msword', 'application/x-ole-storage'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['application/zip', 'application/x-zip'], 'application/vnd.ms-excel' => ['application/vnd.ms-excel', 'application/x-ole-storage'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['application/zip', 'application/x-zip'], 'application/vnd.ms-powerpoint' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'], 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['application/zip', 'application/x-zip'], 'text/plain' => ['text/plain', 'application/octet-stream'], 'text/csv' => ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'], 'application/zip' => ['application/zip', 'application/x-zip'], 'application/x-7z-compressed' => ['application/x-7z-compressed', 'application/octet-stream'], 'application/octet-stream' => ['application/octet-stream']];
    $videoSignatures = ['video/mp4' => ['video/mp4', 'application/mp4', 'application/octet-stream'], 'video/3gpp' => ['video/3gpp', 'application/octet-stream'], 'video/quicktime' => ['video/quicktime', 'application/octet-stream'], 'video/webm' => ['video/webm', 'application/octet-stream']];
    $allowedSignatures = $kind === 'video' ? $videoSignatures[$mime] : $documentSignatures[$mime];
    if (!in_array($detectedMime, $allowedSignatures, true)) {
        wa_media_ingest_reply(['success' => false, 'message' => 'invalid_media_signature'], 415);
    }
}

try {
    wa_messages_ensure_table($pdo);
    $find = $pdo->prepare('SELECT id FROM whatsapp_messages WHERE phone_normalized = ? AND message_id = ? LIMIT 1');
    $find->execute([$phone, mb_substr($messageId, 0, 255)]);
    $rowId = (int)$find->fetchColumn();
    if ($rowId <= 0) wa_media_ingest_reply(['success' => false, 'message' => 'message_not_found'], 404);

    $relativeDir = 'storage/whatsapp/media';
    $absoluteDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('media_storage_unavailable');
    }
    $accessFile = $absoluteDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($accessFile) && file_put_contents($accessFile, "Require all denied\n", LOCK_EX) === false) {
        throw new RuntimeException('media_storage_access_rule_failed');
    }
    $extension = $extensions[$kind][$mime];
    $fileName = hash('sha256', $phone . '|' . $messageId) . '.' . $extension;
    $relativePath = $relativeDir . '/' . $fileName;
    $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($absolutePath, $body, LOCK_EX) === false) throw new RuntimeException('media_write_failed');

    $safeOriginalName = $originalName !== '' ? basename(str_replace('\\', '/', $originalName)) : $fileName;
    $safeOriginalName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $safeOriginalName) ?: $fileName;
    $safeOriginalName = mb_substr($safeOriginalName, 0, 255);
    $update = $pdo->prepare('UPDATE whatsapp_messages SET media_kind = ?, media_mime = ?, media_path = ?, media_filename = ? WHERE id = ?');
    $update->execute([$kind, $mime, $relativePath, $safeOriginalName, $rowId]);
    wa_media_ingest_reply(['success' => true, 'message_id' => $rowId]);
} catch (Throwable $e) {
    error_log('WhatsApp media ingest failed: ' . $e->getMessage());
    wa_media_ingest_reply(['success' => false, 'message' => 'media_save_failed'], 500);
}
