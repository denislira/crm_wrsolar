<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/whatsapp_message_store.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

try {
    wa_messages_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT media_path, media_mime, media_filename FROM whatsapp_messages WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);
    $relativePath = (string)($media['media_path'] ?? '');
    $allowedPrefix = 'storage/whatsapp/media/';
    if (!$media || !str_starts_with($relativePath, $allowedPrefix)) {
        http_response_code(404);
        exit;
    }

    $basePath = realpath(dirname(__DIR__) . '/storage/whatsapp/media');
    $filePath = realpath(dirname(__DIR__) . '/' . $relativePath);
    if (!$basePath || !$filePath || !str_starts_with($filePath, $basePath . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
        http_response_code(404);
        exit;
    }

    $allowedMimes = ['image/jpeg','image/png','image/webp','image/gif','audio/ogg','audio/opus','application/ogg','audio/mpeg','audio/mp4','audio/aac','audio/amr','audio/3gpp','audio/wav','audio/webm','video/mp4','video/3gpp','video/quicktime','video/webm','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation','text/plain','text/csv','application/zip','application/x-7z-compressed','application/octet-stream'];
    $mime = strtolower((string)($media['media_mime'] ?? ''));
    if (!in_array($mime, $allowedMimes, true)) {
        http_response_code(415);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($filePath));
    $filename = basename((string)$media['media_filename']);
    $disposition = str_starts_with($mime, 'application/') || str_starts_with($mime, 'text/') || $mime === 'image/gif' ? 'attachment' : 'inline';
    $asciiFilename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'media';
    $encodedFilename = rawurlencode($filename);
    header('Content-Disposition: ' . $disposition . '; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . $encodedFilename);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
} catch (Throwable $e) {
    http_response_code(500);
}
