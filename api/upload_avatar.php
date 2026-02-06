<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';

$user_id = (int) $_SESSION['user_id'];
// allow admins to upload for any user via ?user_id=ID
$target_user = isset($_POST['user_id']) && is_numeric($_POST['user_id']) ? (int)$_POST['user_id'] : $user_id;

// if trying to update another user, require configuracoes permission
if ($target_user !== $user_id && !hasPermission('configuracoes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$storageDir = __DIR__ . '/../uploads';
if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);

// ensure users table has avatar column (best-effort)
try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar'");
    $check->execute();
    if ($check->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) DEFAULT NULL");
    }
} catch (Exception $e) {
    // ignore schema modification errors (we still continue)
}

if (isset($_POST['remove']) && $_POST['remove'] == '1') {
    // remove avatar
    try {
        $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$target_user]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['avatar'])) {
            $p = __DIR__ . '/../' . $row['avatar'];
            if (file_exists($p)) @unlink($p);
        }
        $u = $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = ?');
        $u->execute([$target_user]);
        echo json_encode(['success'=>true,'message'=>'Avatar removido']);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
    }
    exit;
}

// Basic upload validation
if (empty($_FILES['avatar']) || !isset($_FILES['avatar']['tmp_name'])) {
    $info = [
        'files' => $_FILES,
        'php_ini' => [
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size')
        ]
    ];
    echo json_encode(['success' => false, 'message' => 'Nenhum arquivo recebido', 'info' => $info]);
    exit;
}

$file = $_FILES['avatar'];
$allowed_ext = ['png'=>'.png','jpeg'=>'.jpg','jpg'=>'.jpg','gif'=>'.gif','webp'=>'.webp'];

// check PHP upload error
if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'message'=>'Erro no upload (PHP): '.$file['error'],'files'=>$file]);
    exit;
}

// use getimagesize() to reliably detect image files
$imgInfo = @getimagesize($file['tmp_name']);
$ext = null;
if ($imgInfo && !empty($imgInfo['mime'])) {
    $mime = $imgInfo['mime'];
    // map common mime types to extensions
    $map = ['image/png'=>'.png','image/jpeg'=>'.jpg','image/gif'=>'.gif','image/webp'=>'.webp'];
    if (isset($map[$mime])) $ext = $map[$mime];
}

// fallback: try original filename extension
if ($ext === null) {
    $orig = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (isset($allowed_ext[$orig])) $ext = $allowed_ext[$orig];
}

if ($ext === null) {
    echo json_encode(['success'=>false,'message'=>'Tipo de arquivo não suportado ou arquivo inválido','files'=>$file]);
    exit;
}

// prepare upload path
$targetName = 'uploads/avatar_'.$target_user.$ext;
$targetPath = __DIR__ . '/../' . $targetName;

try {
    // sanity checks
    if (empty($file['tmp_name']) || !file_exists($file['tmp_name'])) throw new Exception('Arquivo temporário não encontrado: ' . ($file['tmp_name'] ?? '')); 
    if (!is_uploaded_file($file['tmp_name'])) {
        // not always true in certain environments; attempt move anyway and include diagnostic
        // but prefer to fail with clear message
        throw new Exception('Arquivo não foi enviado via HTTP POST (is_uploaded_file falhou)');
    }

    if (!is_dir(dirname($targetPath))) @mkdir(dirname($targetPath), 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        // try copy as fallback to surface error
        if (!@copy($file['tmp_name'], $targetPath)) throw new Exception('Falha ao mover arquivo (move_uploaded_file e copy falharam)');
    }
    @chmod($targetPath, 0644);
    // update users table
    $stmt = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
    $stmt->execute([$targetName, $target_user]);
    echo json_encode(['success'=>true,'message'=>'Avatar salvo','avatar'=>$targetName]);
} catch (Exception $e) {
    $diag = ['message'=>$e->getMessage(), 'files'=>$file, 'php_ini'=>['upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size')]];
    echo json_encode(['success'=>false,'message'=>'Erro ao salvar avatar','diag'=>$diag]);
}
