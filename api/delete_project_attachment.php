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
$index = isset($_POST['attachment_index']) ? intval($_POST['attachment_index']) : -1;
if ($projectId <= 0 || $index < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
    exit;
}

$stmt = $pdo->prepare('SELECT doc_attachments FROM projetos WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$projectId, $_SESSION['user_id']]);
$current = $stmt->fetchColumn();
if ($current === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Projeto não encontrado']);
    exit;
}

$attachments = [];
if ($current) {
    $decoded = json_decode($current, true);
    if (is_array($decoded)) {
        $attachments = $decoded;
    }
}

if (!isset($attachments[$index])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Documento não encontrado']);
    exit;
}

$attachment = $attachments[$index];
$path = $attachment['path'] ?? null;
unset($attachments[$index]);
$attachments = array_values($attachments);

$update = $pdo->prepare('UPDATE projetos SET doc_attachments = ? WHERE id = ? AND user_id = ?');
$update->execute([json_encode($attachments), $projectId, $_SESSION['user_id']]);

if ($path) {
    $expectedRoot = realpath(__DIR__ . '/../uploads/project_docs/' . $projectId);
    $filePath = realpath(__DIR__ . '/../' . $path);
    if ($filePath && $expectedRoot && str_starts_with($filePath, $expectedRoot)) {
        @unlink($filePath);
    }
}

echo json_encode(['success' => true, 'attachments' => $attachments]);
