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
include_once '../includes/avatar_image.php';

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

if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || !@getimagesize($file['tmp_name'])) {
    echo json_encode(['success'=>false,'message'=>'Arquivo de imagem inválido ou upload incompleto.']);
    exit;
}

try {
    $targetName = wrcrm_save_avatar_image($file['tmp_name'], $target_user);
    $stmt = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
    $stmt->execute([$targetName, $target_user]);
    echo json_encode(['success'=>true,'message'=>'Avatar salvo','avatar'=>$targetName]);
} catch (Throwable $e) {
    $diag = ['message'=>$e->getMessage(), 'files'=>$file, 'php_ini'=>['upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size')]];
    echo json_encode(['success'=>false,'message'=>'Erro ao salvar avatar','diag'=>$diag]);
}
