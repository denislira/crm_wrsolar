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

$id = $_POST['id'] ?? '';
$username = $_POST['username'] ?? '';
$email = $_POST['email'] ?? '';
$nome_completo = $_POST['nome_completo'] ?? null;
$biografia = $_POST['biografia'] ?? null;
$role_id = $_POST['role_id'] ?? '';
$team_id = $_POST['team_id'] ?? null;
$role_level = isset($_POST['role_level']) ? intval($_POST['role_level']) : 0;

if (empty($id) || empty($username) || empty($role_id)) {
    echo json_encode(['success' => false, 'message' => 'Campos obrigatórios: id, username, role_id']);
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ?, nome_completo = ?, biografia = ?, role_id = ?, team_id = ?, role_level = ? WHERE id = ?');
    $stmt->execute([$username, $email, $nome_completo, $biografia, $role_id, $team_id ?: null, $role_level, $id]);
    // handle avatar upload if provided
    if (!empty($_FILES['avatar']) && isset($_FILES['avatar']['tmp_name']) && file_exists($_FILES['avatar']['tmp_name'])) {
        $file = $_FILES['avatar'];
        $imgInfo = @getimagesize($file['tmp_name']);
        $ext = null;
        $map = ['image/png'=>'.png','image/jpeg'=>'.jpg','image/gif'=>'.gif','image/webp'=>'.webp'];
        if ($imgInfo && !empty($imgInfo['mime']) && isset($map[$imgInfo['mime']])) $ext = $map[$imgInfo['mime']];
        if ($ext === null) {
            $orig = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['png'=>'.png','jpg'=>'.jpg','jpeg'=>'.jpg','gif'=>'.gif','webp'=>'.webp'];
            if (isset($allowed[$orig])) $ext = $allowed[$orig];
        }
        if ($ext !== null) {
            $targetName = 'uploads/avatar_'.$id.$ext;
            $targetPath = __DIR__ . '/../' . $targetName;
            if (!is_dir(dirname($targetPath))) @mkdir(dirname($targetPath), 0755, true);
            if (@move_uploaded_file($file['tmp_name'], $targetPath) || @copy($file['tmp_name'], $targetPath)) {
                @chmod($targetPath, 0644);
                $u = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
                $u->execute([$targetName, $id]);
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Usuário atualizado com sucesso']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar usuário: ' . $e->getMessage()]);
}
?>