<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';

if (!hasPermission('projetos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
if ($projectId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de projeto inválido']);
    exit;
}

$stmt = $pdo->prepare('SELECT id FROM projetos WHERE id = ? AND user_id = ?');
$stmt->execute([$projectId, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Projeto não encontrado']);
    exit;
}

if (empty($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Arquivo inválido ou não enviado']);
    exit;
}

$file = $_FILES['doc_file'];
$allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
$allowedMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/bmp',
    'image/webp',
    'image/svg+xml',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain',
];

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, $allowedExtensions, true)) {
    echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não permitido. Use formatos de imagem ou documentos comuns.']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Tipo de arquivo inválido. Envie uma imagem ou documento permitido.']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/project_docs/' . $projectId;
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
$targetPath = $uploadDir . '/' . time() . '_' . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success' => false, 'message' => 'Falha ao salvar arquivo']);
    exit;
}

// atualizar lista de anexos em JSON
$stmt = $pdo->prepare('SELECT doc_attachments FROM projetos WHERE id = ? AND user_id = ?');
$stmt->execute([$projectId, $_SESSION['user_id']]);
$current = $stmt->fetchColumn();
$attachments = [];
if ($current) {
    $decoded = json_decode($current, true);
    if (is_array($decoded)) {
        $attachments = $decoded;
    }
}
$attachments[] = [
    'name' => $filename,
    'path' => 'uploads/project_docs/' . $projectId . '/' . basename($targetPath),
    'uploaded_at' => date('Y-m-d H:i:s')
];

$update = $pdo->prepare('UPDATE projetos SET doc_attachments = ? WHERE id = ? AND user_id = ?');
$update->execute([json_encode($attachments), $projectId, $_SESSION['user_id']]);

echo json_encode(['success' => true, 'message' => 'Arquivo enviado', 'attachment' => end($attachments)]);
