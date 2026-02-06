<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';

if (!hasPermission('configuracoes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);
$settingsPath = $storageDir . '/settings.json';

$appearance = [];
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $appearance = $raw ? json_decode($raw, true) : [];
}

// Accept primary_color, primary_dark, green, yellow
$primary = isset($_POST['primary_color']) ? trim($_POST['primary_color']) : null;
$primaryDark = isset($_POST['primary_dark']) ? trim($_POST['primary_dark']) : null;
$green = isset($_POST['green']) ? trim($_POST['green']) : null;
$yellow = isset($_POST['yellow']) ? trim($_POST['yellow']) : null;

if ($primary && preg_match('/^#[0-9A-Fa-f]{6}$/', $primary)) $appearance['primary_color'] = $primary;
if ($primaryDark && preg_match('/^#[0-9A-Fa-f]{6}$/', $primaryDark)) $appearance['primary_dark'] = $primaryDark;
if ($green && preg_match('/^#[0-9A-Fa-f]{6}$/', $green)) $appearance['green'] = $green;
if ($yellow && preg_match('/^#[0-9A-Fa-f]{6}$/', $yellow)) $appearance['yellow'] = $yellow;

// Handle logo upload
if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['logo'];
    $allowed = [ 'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/jpg' => '.jpg', 'image/svg+xml' => '.svg' ];
    $mime = mime_content_type($f['tmp_name']);
    if (!array_key_exists($mime, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não suportado. Use PNG/JPG/SVG.']);
        exit;
    }
    $ext = $allowed[$mime];
    $targetName = 'uploads/custom_logo' . $ext;
    $targetPath = __DIR__ . '/../' . $targetName;
    if (move_uploaded_file($f['tmp_name'], $targetPath)) {
        // store relative path
        $appearance['logo'] = $targetName;
    } else {
        echo json_encode(['success' => false, 'message' => 'Falha ao mover arquivo.']);
        exit;
    }
}

// Handle collapsed logo upload (small logo shown when sidebar is collapsed)
if (!empty($_FILES['logo_collapsed']) && $_FILES['logo_collapsed']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['logo_collapsed'];
    $allowed = [ 'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/jpg' => '.jpg', 'image/svg+xml' => '.svg' ];
    $mime = mime_content_type($f['tmp_name']);
    if (!array_key_exists($mime, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não suportado para logo recolhida. Use PNG/JPG/SVG.']);
        exit;
    }
    $ext = $allowed[$mime];
    $targetName = 'uploads/custom_logo_collapsed' . $ext;
    $targetPath = __DIR__ . '/../' . $targetName;
    if (move_uploaded_file($f['tmp_name'], $targetPath)) {
        $appearance['logo_collapsed'] = $targetName;
    } else {
        echo json_encode(['success' => false, 'message' => 'Falha ao mover arquivo collapsed.']);
        exit;
    }
}

// If requested, remove logo
if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
    if (!empty($appearance['logo'])) {
        $p = __DIR__ . '/../' . $appearance['logo'];
        if (file_exists($p)) @unlink($p);
        unset($appearance['logo']);
    }
}
// Remove collapsed logo
if (isset($_POST['remove_logo_collapsed']) && $_POST['remove_logo_collapsed'] === '1') {
    if (!empty($appearance['logo_collapsed'])) {
        $p = __DIR__ . '/../' . $appearance['logo_collapsed'];
        if (file_exists($p)) @unlink($p);
        unset($appearance['logo_collapsed']);
    }
}

// Save settings
if (file_put_contents($settingsPath, json_encode($appearance, JSON_PRETTY_PRINT))) {
    echo json_encode(['success' => true, 'message' => 'Aparência salva com sucesso', 'appearance' => $appearance]);
} else {
    echo json_encode(['success' => false, 'message' => 'Falha ao salvar configurações']);
}
